#!/usr/bin/env python3
"""Probe real Docker/Codex isolation using only synthetic data and no inference."""

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import secrets
import selectors
import subprocess
import tempfile
import threading
import time


def docker(*arguments, input_data=None, timeout=30):
    result = subprocess.run(["docker", *arguments], input=input_data, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    if result.returncode:
        raise RuntimeError("The disposable Docker operation failed: " + arguments[0])
    return result.stdout


def rpc_probe(container, expected_marker, forbidden_host_file, barrier):
    started = time.monotonic()
    command = ["docker", "exec", "-i", container, "codex", "app-server", "--listen", "stdio://",
               "-c", "model_provider=\"synthetic_no_inference\"",
               "-c", "model_providers.synthetic_no_inference.name=\"Synthetic isolation probe\"",
               "-c", "model_providers.synthetic_no_inference.base_url=\"http://127.0.0.1:9/v1\"",
               "-c", "model_providers.synthetic_no_inference.env_key=\"ACCESS_TOKEN\"",
               "-c", "model_providers.synthetic_no_inference.wire_api=\"responses\"",
               "-c", "model_providers.synthetic_no_inference.requires_openai_auth=false",
               "-c", "project_doc_max_bytes=0", "-c", "features.shell_tool=false",
               "-c", "features.unified_exec=false", "-c", "features.apps=false",
               "-c", "features.plugins=false", "-c", "features.skip_host_skill_discovery=true",
               "-c", "features.multi_agent=false", "-c", "features.hooks=false"]
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ, "stdout")
    selector.register(process.stderr, selectors.EVENT_READ, "stderr")
    buffer = b""
    diagnostics = b""

    def send(value):
        process.stdin.write(json.dumps(value).encode() + b"\n")
        process.stdin.flush()

    def response(identifier):
        nonlocal buffer, diagnostics
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            while b"\n" in buffer:
                line, buffer = buffer.split(b"\n", 1)
                if not line:
                    continue
                message = json.loads(line)
                if message.get("id") == identifier and "method" not in message:
                    if "error" in message:
                        raise RuntimeError("The isolated Codex rejected probe request " + str(identifier))
                    return message["result"]
                if "id" in message and "method" in message:
                    raise RuntimeError("An unexpected native approval or tool request occurred.")
            for key, _ in selector.select(timeout=0.2):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    selector.unregister(key.fileobj)
                elif key.data == "stdout":
                    buffer += chunk
                else:
                    diagnostics = (diagnostics + chunk)[-32768:]
            if process.poll() is not None and not selector.get_map():
                break
        raise RuntimeError("The isolated Codex probe did not return a complete response. Synthetic diagnostics: " + diagnostics.decode("utf-8", errors="replace")[-2000:])

    try:
        send({"id": 1, "method": "initialize", "params": {
            "clientInfo": {"name": "ganalise_isolation_probe", "title": "G-Analise isolation probe", "version": "1.0.0"},
            "capabilities": {"experimentalApi": True}}})
        initialized = response(1)
        send({"method": "initialized", "params": {}})
        barrier.wait(timeout=10)
        script = r'''set -eu
[ "$(id -u)" = 1000 ]
[ "$(stat -c %a /home/agent)" = 700 ]
[ "$(cat /home/agent/owner-canary)" = "$1" ]
[ ! -e "$2" ]
[ ! -e /var/www/html/.env ]
[ ! -e /var/www/html/artisan ]
[ ! -S /var/run/docker.sock ]
[ ! -S /run/docker.sock ]
[ ! -e /home/other-user/owner-canary ]
[ -z "${APP_KEY:-}" ]
[ -z "${DB_PASSWORD:-}" ]
[ -z "${OPENAI_CLIENT_SECRET:-}" ]
[ -z "${LUMO_PASSWORD:-}" ]
if touch /etc/ganalise-isolation-canary 2>/dev/null; then exit 42; fi
printf 'ISOLATION_PROBE_OK\n'
'''
        send({"id": 2, "method": "command/exec", "params": {
            "command": ["/bin/sh", "-c", script, "sh", expected_marker, forbidden_host_file],
            "cwd": "/workspace", "sandboxPolicy": {"type": "externalSandbox", "networkAccess": "restricted"},
            "timeoutMs": 5000}})
        result = response(2)
        if result.get("exitCode") != 0 or result.get("stdout", "").strip() != "ISOLATION_PROBE_OK":
            raise RuntimeError("The isolated process could not prove its filesystem and environment boundary.")
        return {"initialized": isinstance(initialized, dict), "native_command_probe": True,
                "external_sandbox": True, "inference_requested": False,
                "_interval": [started, time.monotonic()]}
    finally:
        selector.close()
        if process.stdin:
            process.stdin.close()
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)
        process.stdout.close()
        process.stderr.close()


def inspect_boundary(container):
    details = json.loads(docker("inspect", container))[0]
    host = details["HostConfig"]
    config = details["Config"]
    expected = {
        "non_root": config["User"] == "1000:1000",
        "readonly_root": host["ReadonlyRootfs"] is True,
        "network_disabled": host["NetworkMode"] == "none",
        "no_host_pid": host.get("PidMode", "") != "host",
        "no_privileged": host["Privileged"] is False,
        "all_capabilities_dropped": "ALL" in host.get("CapDrop", []),
        "no_new_privileges": "no-new-privileges" in host.get("SecurityOpt", []),
        "default_seccomp": not any("seccomp=unconfined" in option for option in host.get("SecurityOpt", [])),
        "no_host_or_shared_volume_mounts": details["Mounts"] == [],
        "no_published_ports": not host.get("PortBindings"),
        "memory_bounded": host["Memory"] == 268435456,
        "cpu_bounded": host["NanoCpus"] == 1000000000,
        "processes_bounded": host["PidsLimit"] == 64,
    }
    if not all(expected.values()):
        raise RuntimeError("A disposable container did not have all required runtime boundaries.")
    return expected


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--disposable", action="store_true", required=True)
    parser.add_argument("--image", default="horus-ia-php:local")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.output and args.output.exists():
        parser.error("The report path already exists; choose a new file.")
    image_id = docker("image", "inspect", args.image, "--format", "{{.Id}}").decode().strip()
    if not image_id.startswith("sha256:") or len(image_id) != 71:
        raise RuntimeError("A local immutable image ID is required.")
    nonce = secrets.token_hex(8)
    containers = []
    report = {"scope": "isolated_runtime_prototype", "synthetic_only": True, "inference_requested": False,
              "production_integrated": False, "hosted_authentication_validated": False, "image_id": image_id, "actors": []}
    try:
        with tempfile.TemporaryDirectory(prefix="ganalise-isolation-host-") as temporary:
            forbidden_file = Path(temporary, "host-only-canary")
            forbidden_file.write_text("SYNTHETIC-HOST-ONLY-" + nonce)
            forbidden_file.chmod(0o600)
            markers = ["SYNTHETIC-A-" + secrets.token_hex(16), "SYNTHETIC-B-" + secrets.token_hex(16)]
            for index in range(2):
                name = "ganalise-isolation-" + nonce + "-" + str(index)
                container = docker("run", "-d", "--rm", "--name", name,
                                   "--label", "io.ganalise.test=" + nonce,
                                   "--user", "1000:1000", "--network", "none", "--read-only",
                                   "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
                                   "--memory", "256m", "--cpus", "1", "--pids-limit", "64",
                                   "--tmpfs", "/tmp:rw,noexec,nosuid,nodev,size=16m,mode=1777",
                                   "--tmpfs", "/home/agent:rw,noexec,nosuid,nodev,size=32m,mode=0700,uid=1000,gid=1000",
                                   "--tmpfs", "/workspace:rw,noexec,nosuid,nodev,size=16m,mode=0700,uid=1000,gid=1000",
                                   "--workdir", "/workspace", "--env", "HOME=/home/agent",
                                   "--env", "CODEX_HOME=/home/agent/.codex", "--env", "ACCESS_TOKEN=SYNTHETIC-NO-INFERENCE",
                                   image_id, "/bin/sleep", "600").decode().strip()
                containers.append(container)
                boundary = inspect_boundary(container)
                docker("exec", "-i", container, "/bin/sh", "-c", "umask 077; mkdir -p /home/agent/.codex; cat > /home/agent/owner-canary", input_data=markers[index].encode())
                report["actors"].append({"runtime": boundary})
            for index, container in enumerate(containers):
                actual = docker("exec", container, "cat", "/home/agent/owner-canary").decode()
                if actual != markers[index] or actual == markers[1-index]:
                    raise RuntimeError("The synthetic identities did not have distinct private storage.")
                report["actors"][index]["private_marker_isolated"] = True
            barrier = threading.Barrier(2)
            with ThreadPoolExecutor(max_workers=2) as executor:
                futures = [executor.submit(rpc_probe, container, markers[index], str(forbidden_file), barrier)
                           for index, container in enumerate(containers)]
                intervals = []
                for index, future in enumerate(futures):
                    result = future.result()
                    intervals.append(result.pop("_interval"))
                    report["actors"][index]["codex"] = result
            report["parallel_overlap_verified"] = max(item[0] for item in intervals) < min(item[1] for item in intervals)
            if not report["parallel_overlap_verified"]:
                raise RuntimeError("Parallel isolated Codex processes were not observed.")
            report["codex_version"] = docker("exec", containers[0], "codex", "--version").decode().strip()
            report["ok"] = True
    finally:
        for container in containers:
            try:
                actual_label = docker("inspect", "--format", '{{index .Config.Labels "io.ganalise.test"}}', container).decode().strip()
                if actual_label == nonce:
                    docker("rm", "-f", container)
            except RuntimeError:
                pass
        remaining = docker("ps", "-aq", "--filter", "label=io.ganalise.test=" + nonce).decode().strip()
        if remaining:
            raise RuntimeError("Disposable container cleanup was not confirmed.")
        report["cleanup_confirmed"] = True
    serialized = json.dumps(report, indent=2)
    if args.output:
        args.output.write_text(serialized + "\n")
        args.output.chmod(0o600)
    print(serialized)


if __name__ == "__main__":
    main()

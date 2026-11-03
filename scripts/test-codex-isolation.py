#!/usr/bin/env python3
"""Probe real Docker/Codex isolation using only synthetic data and no inference."""

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import os
import re
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
        details = re.sub(r"gaigr_[A-Za-z0-9]{64}|base64:[A-Za-z0-9+/=]+", "[redacted]", result.stderr.decode(errors="replace")[-2000:])
        raise RuntimeError("The disposable Docker operation failed: " + arguments[0] + ": " + details)
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
    parser.add_argument("--agent-image", help="Run synthetic turns through an isolated-agent image and a Unix gateway.")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.output and args.output.exists():
        parser.error("The report path already exists; choose a new file.")
    if args.agent_image:
        gateway_main(args)
        return
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


class GatewayRpcSession:
    def __init__(self, command):
        self.process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.selector = selectors.DefaultSelector()
        self.selector.register(self.process.stdout, selectors.EVENT_READ, 'stdout')
        self.selector.register(self.process.stderr, selectors.EVENT_READ, 'stderr')
        self.buffer = b''
        self.diagnostics = b''

    def send(self, value):
        self.process.stdin.write(json.dumps(value).encode() + b'\n')
        self.process.stdin.flush()

    def receive(self, timeout=20):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            while b'\n' in self.buffer:
                line, self.buffer = self.buffer.split(b'\n', 1)
                if line:
                    return json.loads(line)
            for key, _ in self.selector.select(timeout=.1):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    self.selector.unregister(key.fileobj)
                elif key.data == 'stdout':
                    self.buffer += chunk
                else:
                    self.diagnostics = (self.diagnostics + chunk)[-4096:]
            if self.process.poll() is not None and not self.selector.get_map():
                break
        raise RuntimeError('The synthetic gateway RPC did not return a complete message. ' + self.diagnostics.decode(errors='replace')[-1000:])

    def response(self, identifier):
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            message = self.receive()
            if message.get('id') == identifier and 'method' not in message:
                if 'error' in message:
                    raise RuntimeError('The isolated gateway probe rejected its RPC contract.')
                return message['result']
            if 'id' in message and 'method' in message:
                raise RuntimeError('An unexpected native operation was requested.')
        raise RuntimeError('The isolated gateway RPC response expired.')

    def close(self):
        self.selector.close()
        if self.process.stdin:
            self.process.stdin.close()
        if self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=3)
        self.process.stdout.close()
        self.process.stderr.close()


def isolated_agent_command(name, authorization, deadline, image_id, volume, nonce):
    command = ['docker', 'run', '--rm', '-i', '--name', name, '--label', 'io.ganalise.test=' + nonce,
               '--user', '1000:1000', '--network', 'none', '--read-only', '--cap-drop', 'ALL',
               '--security-opt', 'no-new-privileges', '--memory', '512m', '--cpus', '1', '--pids-limit', '64',
               '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=16m,mode=1777',
               '--tmpfs', '/home/agent:rw,noexec,nosuid,nodev,size=64m,mode=0700,uid=1000,gid=1000',
               '--mount', 'type=volume,src=' + volume + ',dst=/run/agent-inference,readonly',
               '--env', 'ACCESS_TOKEN=' + authorization, '--env', 'AGENT_RUN_DEADLINE=' + str(deadline), image_id]
    return command


def isolated_gateway_actor(name, actor, image_id, volume, nonce, host_canary, barrier):
    started = time.monotonic()
    command = isolated_agent_command(name, actor['authorization'], int(time.time()) + 120, image_id, volume, nonce)
    session = GatewayRpcSession(command)
    try:
        session.send({'id': 1, 'method': 'initialize', 'params': {'clientInfo': {'name': 'G-Analise IA', 'title': 'G-Analise IA', 'version': '0.1.0'}, 'capabilities': {'experimentalApi': True}}})
        session.response(1)
        session.send({'method': 'initialized', 'params': {}})
        marker = ('SYNTHETIC-PRIVATE-' + actor['label']).encode()
        docker('exec', '-i', name, '/bin/sh', '-c', 'umask 077; cat > /home/agent/owner-canary', input_data=marker)
        details = json.loads(docker('inspect', name))[0]
        host = details['HostConfig']
        checks = {
            'non_root': details['Config']['User'] == '1000:1000',
            'readonly_root': host['ReadonlyRootfs'] is True, 'network_disabled': host['NetworkMode'] == 'none',
            'no_privileged': host['Privileged'] is False, 'all_capabilities_dropped': 'ALL' in host.get('CapDrop', []),
            'no_new_privileges': 'no-new-privileges' in host.get('SecurityOpt', []),
            'no_host_pid': host.get('PidMode', '') != 'host', 'no_published_ports': not host.get('PortBindings'),
            'memory_bounded': host['Memory'] == 536870912, 'cpu_bounded': host['NanoCpus'] == 1000000000,
            'processes_bounded': host['PidsLimit'] == 64,
            'only_readonly_gateway_socket_volume': len(details['Mounts']) == 1 and details['Mounts'][0]['Type'] == 'volume' and details['Mounts'][0]['Name'] == volume and details['Mounts'][0]['Destination'] == '/run/agent-inference' and details['Mounts'][0]['RW'] is False,
            'no_backend_secrets_in_environment': not any(item.startswith(('APP_KEY=', 'DB_PASSWORD=', 'OPENAI_CLIENT_SECRET=', 'AGENT_INFERENCE_SECRET=', 'AGENT_LAUNCHER_SECRET=')) for item in details['Config']['Env']),
        }
        if not all(checks.values()):
            raise RuntimeError('The isolated gateway actor lacks a required container boundary.')
        probe = r'''set -eu
[ "$(id -u)" = 1000 ]
[ "$(cat /home/agent/owner-canary)" = "$1" ]
[ ! -e "$2" ]
[ ! -e /var/www/html/artisan ]
[ ! -e /var/www/html/.env ]
[ ! -S /var/run/docker.sock ]
[ ! -S /run/docker.sock ]
[ -z "${APP_KEY:-}" ]
[ -z "${DB_PASSWORD:-}" ]
if touch /etc/ganalise-forbidden 2>/dev/null; then exit 42; fi
/usr/local/bin/php -r '$s=@stream_socket_client("tcp://192.0.2.1:443",$e,$m,.2);if(is_resource($s)){fclose($s);exit(41);}'
/usr/local/bin/php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true,"timeout"=>2]]);@file_get_contents("http://127.0.0.1:8091/forbidden",false,$c);if(!str_contains($http_response_header[0]??"","404")){exit(43);}'
printf 'ISOLATED_GATEWAY_BOUNDARY_OK\n'
'''
        session.send({'id': 2, 'method': 'command/exec', 'params': {'command': ['/bin/sh', '-c', probe, 'sh', marker.decode(), host_canary], 'cwd': '/home/agent/execution', 'sandboxPolicy': {'type': 'externalSandbox', 'networkAccess': 'restricted'}, 'timeoutMs': 5000}})
        result = session.response(2)
        if result.get('exitCode') != 0 or result.get('stdout', '').strip() != 'ISOLATED_GATEWAY_BOUNDARY_OK':
            raise RuntimeError('The native actor did not prove its filesystem and direct-network boundary.')
        session.send({'id': 3, 'method': 'thread/start', 'params': {'model': 'gpt-synthetic', 'cwd': '/home/agent/execution', 'approvalPolicy': 'untrusted', 'sandbox': 'read-only', 'dynamicTools': []}})
        thread = session.response(3)['thread']['id']
        barrier.wait(timeout=15)
        session.send({'id': 4, 'method': 'turn/start', 'params': {'threadId': thread, 'input': [{'type': 'text', 'text': 'SYNTHETIC_NATIVE_GATEWAY_QUESTION_' + actor['label']}], 'approvalPolicy': 'untrusted', 'sandboxPolicy': {'type': 'readOnly', 'networkAccess': False}}})
        text_parts = []
        completed = None
        deadline = time.monotonic() + 30
        while completed is None and time.monotonic() < deadline:
            message = session.receive()
            if 'error' in message:
                raise RuntimeError('The native gateway turn failed its RPC contract.')
            if message.get('method') == 'item/agentMessage/delta':
                text_parts.append(message['params'].get('delta', ''))
            if message.get('method') == 'turn/completed':
                completed = message['params']['turn'].get('status')
            if 'id' in message and 'method' in message:
                raise RuntimeError('The synthetic gateway turn requested an unexpected local operation.')
        if completed != 'completed' or ''.join(text_parts) != 'SYNTHETIC_NATIVE_GATEWAY_COMPLETED_' + actor['label']:
            raise RuntimeError('The isolated native actor did not receive its own complete synthetic response.')
        return {'actor': actor['label'], 'runtime': checks, 'native_boundary_probe': True, 'native_turn_completed': True,
                'own_response_verified': True, 'external_inference_requested': False, '_interval': [started, time.monotonic()]}
    finally:
        session.close()



def rejected_agent_initializations(image_id, volume, nonce, names):
    cases = [
        ('non_task_credential', 'SYNTHETIC-OAUTH-CREDENTIAL', 120, '1000:1000', None),
        ('expired_deadline', 'gaigr_' + 'a' * 64, -1, '1000:1000', None),
        ('excessive_deadline', 'gaigr_' + 'a' * 64, 3600, '1000:1000', None),
        ('privileged_user', 'gaigr_' + 'a' * 64, 120, '0:0', None),
        ('backend_secret', 'gaigr_' + 'a' * 64, 120, '1000:1000', 'APP_KEY=SYNTHETIC-BACKEND-SECRET'),
    ]
    results = {}
    for index, (case, token, offset, user, secret) in enumerate(cases):
        name = 'ganalise-agent-' + nonce + '-guard-' + str(index)
        names.append(name)
        command = isolated_agent_command(name, token, int(time.time()) + offset, image_id, volume, nonce)
        command[command.index('--user') + 1] = user
        if secret:
            command[-1:-1] = ['--env', secret]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15, check=False)
        if result.returncode != 1 or result.stdout != b'' or 'Não foi possível iniciar a execução isolada da IA.' not in result.stderr.decode(errors='replace'):
            raise RuntimeError('The isolated entrypoint did not reject unsafe initialization: ' + case)
        results[case] = True
    return results


def gateway_main(args):
    import base64
    root = Path(__file__).resolve().parent.parent
    image_id = docker('image', 'inspect', args.agent_image, '--format', '{{.Id}}').decode().strip()
    backend_image = docker('image', 'inspect', args.image, '--format', '{{.Id}}').decode().strip()
    proxy_image = docker('image', 'inspect', 'nginxinc/nginx-unprivileged:stable-alpine', '--format', '{{.Id}}').decode().strip()
    nonce = secrets.token_hex(8)
    label = 'io.ganalise.test=' + nonce
    network = 'ganalise-agent-gateway-' + nonce
    volume = 'ganalise-agent-socket-' + nonce
    backend = 'ganalise-agent-backend-' + nonce
    proxy = 'ganalise-agent-proxy-' + nonce
    names = [backend, proxy, 'ganalise-agent-' + nonce + '-a', 'ganalise-agent-' + nonce + '-b']
    report = {'scope': 'isolated_runtime_gateway_prototype', 'synthetic_only': True, 'external_inference_requested': False,
              'production_integrated': False, 'hosted_authentication_validated': False, 'image_id': image_id, 'actors': []}
    created_network = False
    created_volume = False
    state_parent = root / '.runtime'
    state_parent.mkdir(mode=0o700, exist_ok=True)
    temporary = tempfile.TemporaryDirectory(prefix='gateway-probe-', dir=state_parent)
    temporary_path = Path(temporary.name)
    actual_root = temporary_path / 'state'
    actual_root.mkdir(mode=0o700)
    backend_root = Path('/tmp/ganalise-inference-native-' + nonce)
    try:
        config = temporary_path / 'nginx.conf'
        config.write_text('''pid /tmp/nginx.pid;
events { worker_connections 64; }
http {
  access_log off;
  error_log /dev/stderr crit;
  client_body_temp_path /tmp/client-body;
  proxy_temp_path /tmp/proxy;
  fastcgi_temp_path /tmp/fastcgi;
  uwsgi_temp_path /tmp/uwsgi;
  scgi_temp_path /tmp/scgi;
  client_max_body_size 48m;
  server {
    listen unix:/socket/http.sock;
    location = /v1/responses {
      if ($request_method != POST) { return 405; }
      proxy_pass http://''' + backend + ''':8000/internal/inference/v1/responses;
      proxy_set_header X-G-Analise-Inference "SYNTHETIC-INTERNAL-GATEWAY-SECRET-DO-NOT-USE-OUTSIDE-TESTS";
      proxy_set_header Host localhost;
      proxy_set_header Connection "";
      proxy_http_version 1.1;
      proxy_buffering off;
      proxy_request_buffering off;
      proxy_read_timeout 30s;
    }
    location / { return 404; }
  }
}
''')
        config.chmod(0o644)
        docker('network', 'create', '--internal', '--label', label, network)
        created_network = True
        docker('volume', 'create', '--label', label, volume)
        created_volume = True
        docker('run', '--rm', '--network', 'none', '--user', '0:0', '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--read-only',
               '--label', label, '--mount', 'type=volume,src=' + volume + ',dst=/socket', backend_image,
               'php', '-r', 'if(!chmod("/socket",0755)||!chgrp("/socket",101)||!chown("/socket",101)){exit(1);}')
        app_key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
        docker('run', '-d', '--name', backend, '--label', label, '--network', network, '--user', str(os.getuid()) + ':' + str(os.getgid()), '--read-only',
               '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=64m,mode=1777',
               '--mount', 'type=bind,src=' + str(root) + ',dst=/var/www/html,readonly',
               '--mount', 'type=bind,src=' + str(actual_root) + ',dst=' + str(backend_root),
               '--env', 'APP_ENV=testing', '--env', 'APP_DEBUG=false', '--env', 'APP_KEY=' + app_key,
               '--env', 'APP_URL=http://localhost', '--env', 'BROADCAST_CONNECTION=null', '--env', 'QUEUE_CONNECTION=sync',
               '--env', 'SESSION_DRIVER=array', '--env', 'CACHE_STORE=file', '--env', 'LOG_CHANNEL=stderr',
               '--env', 'DB_CONNECTION=sqlite', '--env', 'DB_DATABASE=' + str(backend_root / 'database.sqlite'),
               '--env', 'GANALISE_NATIVE_TEST_ROOT=' + str(backend_root), '--env', 'GANALISE_NATIVE_TEST_ACTORS=2',
               '--env', 'GANALISE_NATIVE_TEST_PROXY=external', backend_image, 'php', '-S', '0.0.0.0:8000', 'tests/Fixtures/inference-native-server.php')
        seeded = docker('exec', backend, 'php', 'tests/Fixtures/inference-native-server.php', timeout=30)
        try:
            actors = json.loads(seeded)['actors']
        except (json.JSONDecodeError, KeyError):
            details = re.sub(r'gaigr_[A-Za-z0-9]{64}|base64:[A-Za-z0-9+/=]+', '[redacted]', seeded.decode(errors='replace')[-2000:])
            raise RuntimeError('The synthetic backend did not initialize its two actors: ' + details) from None
        docker('run', '-d', '--name', proxy, '--label', label, '--network', network, '--read-only', '--user', '101:101',
               '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=16m,mode=1777',
               '--mount', 'type=volume,src=' + volume + ',dst=/socket',
               '--mount', 'type=bind,src=' + str(config) + ',dst=/etc/nginx/nginx.conf,readonly',
               '--entrypoint', 'nginx', proxy_image, '-g', 'daemon off;')
        ready = False
        until = time.monotonic() + 10
        while time.monotonic() < until:
            try:
                docker('exec', proxy, '/bin/sh', '-c', 'test -S /socket/http.sock')
                ready = True
                break
            except RuntimeError:
                time.sleep(.1)
        if not ready:
            logs = subprocess.run(['docker', 'logs', '--tail', '20', proxy], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10, check=False)
            details = re.sub(r'gaigr_[A-Za-z0-9]{64}|base64:[A-Za-z0-9+/=]+', '[redacted]', (logs.stdout + logs.stderr).decode(errors='replace')[-2000:])
            raise RuntimeError('The disposable Unix gateway did not start: ' + details)
        report['initialization_guards'] = rejected_agent_initializations(image_id, volume, nonce, names)
        host_canary = actual_root / 'host-only-canary'
        host_canary.write_text('SYNTHETIC-HOST-ONLY-' + nonce)
        barrier = threading.Barrier(2)
        with ThreadPoolExecutor(max_workers=2) as executor:
            futures = [executor.submit(isolated_gateway_actor, names[index + 2], actor, image_id, volume, nonce, str(host_canary), barrier)
                       for index, actor in enumerate(actors)]
            intervals = []
            for future in futures:
                result = future.result()
                intervals.append(result.pop('_interval'))
                receipt = json.loads((actual_root / ('receipt-' + result['actor'] + '.json')).read_text())
                expected = {'requests': 1, 'temporary_bearer_at_gateway': True, 'proxy_secret_absent_from_native_request': False,
                            'backend_oauth_at_provider': True, 'proxy_secret_absent_from_provider_request': True,
                            'prompt_received': True, 'stream': True, 'store': False, 'proxy_added_valid_secret': True, 'actor': result['actor']}
                if receipt != expected:
                    raise RuntimeError('The common gateway did not preserve the actor authorization boundary.')
                result['backend_identity_verified'] = True
                report['actors'].append(result)
        report['parallel_overlap_verified'] = max(pair[0] for pair in intervals) < min(pair[1] for pair in intervals)
        report['ok'] = report['parallel_overlap_verified'] and len(report['actors']) == 2
        if not report['ok']:
            raise RuntimeError('Concurrent isolated gateway turns were not demonstrated.')
    finally:
        for name in names:
            try:
                actual_label = docker('inspect', '--format', '{{index .Config.Labels "io.ganalise.test"}}', name).decode().strip()
                if actual_label == nonce:
                    docker('rm', '-f', name)
            except RuntimeError:
                pass
        remaining = docker('ps', '-aq', '--filter', 'label=' + label).decode().strip()
        if remaining:
            raise RuntimeError('Disposable gateway container cleanup was not confirmed.')
        if created_network:
            docker('network', 'rm', network)
        if created_volume:
            docker('volume', 'rm', volume)
        temporary.cleanup()
        report['cleanup_confirmed'] = True
    serialized = json.dumps(report, indent=2)
    if args.output:
        args.output.write_text(serialized + '\n')
        args.output.chmod(0o600)
    print(serialized)


if __name__ == "__main__":
    main()

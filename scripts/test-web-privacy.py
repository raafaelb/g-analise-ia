#!/usr/bin/env python3
"""Conferir logs com marcadores fictícios em Nginx isolado, sem rede ou banco."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent
MARKERS = {kind: "synthetic-privacy-" + kind + "-" + uuid.uuid4().hex for kind in ("query", "state", "header", "cookie", "referer", "body")}


def docker(*arguments, check=True, timeout=20):
    return subprocess.run(["docker", *arguments], check=check, capture_output=True, text=True, timeout=timeout)


def request(name, path, *, sensitive=False, post=False):
    command = ["exec", name, "wget", "-S", "-O", "/dev/null", "-T", "5"]
    if sensitive:
        path += "?code=" + MARKERS["query"] + "&state=" + MARKERS["state"]
        for value in ("Authorization: Bearer " + MARKERS["header"], "Cookie: session=" + MARKERS["cookie"], "Referer: https://example.invalid/?code=" + MARKERS["referer"]):
            command += ["--header", value]
    if post:
        command += ["--post-data", "password=" + MARKERS["body"]]
    result = docker(*command, "http://127.0.0.1:8080" + path, check=False, timeout=8)
    statuses = re.findall(r"HTTP/\d(?:\.\d)?\s+(\d{3})", result.stdout + result.stderr)
    return int(statuses[-1]) if statuses else None


def logs(name):
    result = docker("logs", name)
    content = result.stdout + result.stderr
    if any(marker in content for marker in MARKERS.values()):
        raise RuntimeError("O proxy registrou um marcador fictício de autenticação. Nenhuma credencial real foi usada.")
    records = []
    for line in content.splitlines():
        try:
            item = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(item, dict) and "path" in item:
            records.append(item)
    return records


def probe(image, *, proxy_example=False):
    name = "ganalise-log-" + ("proxy-" if proxy_example else "web-") + uuid.uuid4().hex[:12]
    temporary_root = ROOT / "storage/framework/testing"
    temporary_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="ganalise-log-", dir=temporary_root) as directory:
        options = ["run", "--detach", "--rm", "--name", name, "--network", "none", "--add-host", "app:127.0.0.1", "--add-host", "reverb:127.0.0.1", "--read-only", "--tmpfs", "/tmp:rw,nosuid,nodev,size=32m"]
        if proxy_example:
            configuration = Path(directory) / "default.conf"
            configuration.write_text("server {\nlisten 8080;\nserver_name _;\n" + (ROOT / "docker/proxy-location.example.conf").read_text() + "\n}\n")
            options += ["--mount", "type=bind,source=" + str(configuration) + ",target=/etc/nginx/conf.d/default.conf,readonly"]
        try:
            docker(*options, image)
            ready_path = "/ia-ganalise/login" if proxy_example else "/brand/g-analise-icon.png"
            deadline = time.monotonic() + 12
            while request(name, ready_path) is None:
                if time.monotonic() >= deadline:
                    raise RuntimeError("O Nginx descartável não iniciou no prazo.")
                time.sleep(0.1)
            prefix = "/ia-ganalise" if proxy_example else ""
            cases = [("GET", prefix + "/brand/g-analise-icon.png", 502 if proxy_example else 200), ("GET", prefix + "/auth/openai/callback", 502), ("POST", prefix + "/auth/bi-mcp/callback", 502)]
            for method, path, expected in cases:
                status = request(name, path, sensitive=True, post=method == "POST")
                if status != expected:
                    raise RuntimeError("O cenário descartável retornou um status HTTP inesperado.")
            records = logs(name)
            if proxy_example:
                if records:
                    raise RuntimeError("O proxy externo de exemplo deve delegar os logs à aplicação.")
                if request(name, "/ia-ganalise", sensitive=True) != 502:
                    raise RuntimeError("O redirecionamento de subdiretório não chegou ao cenário esperado.")
                logs(name)
            else:
                deadline = time.monotonic() + 3
                while len(records) < len(cases) + 1 and time.monotonic() < deadline:
                    time.sleep(0.1)
                    records = logs(name)
                for method, path, expected in cases:
                    if not any(item.get("method") == method and item.get("path") == path and item.get("status") == expected and isinstance(item.get("duration"), (float, int)) for item in records):
                        raise RuntimeError("O log não preservou método, caminho, status e duração do cenário.")
            return {"ok": True, "query_headers_body_absent": True, "upstream_failure_tested": True, "network": "none", "published_ports": 0, "path_status_duration_preserved": not proxy_example, "external_proxy_logging_disabled": proxy_example}
        finally:
            docker("rm", "--force", name, check=False)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", default="horus-ia-web:production")
    args = parser.parse_args()
    result = {"ok": True, "web": probe(args.image), "proxy_example": probe(args.image, proxy_example=True), "scope": "synthetic markers; no application, credentials, database, provider calls or public ports"}
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as error:
        raise SystemExit(error.stderr.strip() or str(error))
    except (RuntimeError, subprocess.SubprocessError, OSError) as error:
        raise SystemExit(str(error))

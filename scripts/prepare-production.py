#!/usr/bin/env python3
"""Gera uma configuração nova de produção, sem alterar uma instalação existente."""
import argparse
import base64
import ipaddress
import os
from pathlib import Path
import re
import secrets
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent.parent


def public_address(value):
    try:
        address = urlsplit(value)
        host = (address.hostname or "").encode("idna").decode("ascii").lower()
        port = address.port
    except (ValueError, UnicodeError):
        raise ValueError("URL pública inválida.") from None
    if (
        address.scheme != "https" or address.username is not None
        or address.password is not None or address.query or address.fragment
        or port not in (None, 443) or "?" in value or "#" in value
        or not re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?", host)
        or "." not in host or ".." in host or host.endswith(".localhost")
        or any(len(label) > 63 or label.startswith("-") or label.endswith("-") for label in host.split("."))
        or any(character.isspace() for character in value)
    ):
        raise ValueError("Informe uma URL HTTPS pública, sem credenciais, parâmetros ou porta diferente de 443.")
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise ValueError("Use o domínio público da instalação, em vez de um endereço IP.")
    path = address.path.rstrip("/")
    if path and not re.fullmatch(r"(?:/[A-Za-z0-9_-]+)+", path):
        raise ValueError("O caminho público deve conter somente nomes separados por barras.")
    return "https://" + host + path, host, path


def prepare(template, output, url, port):
    url, host, path = public_address(url)
    if not 1024 <= port <= 65535:
        raise ValueError("Escolha uma porta interna de 1024 a 65535.")
    values = {
        "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
        "APP_URL": url,
        "ASSET_URL": url,
        "PUBLIC_HOST": host,
        "PUBLIC_PATH": path,
        "APP_PORT": str(port),
        "SESSION_PATH": path or "/",
        "DB_PASSWORD": secrets.token_urlsafe(32),
        "REVERB_APP_KEY": secrets.token_urlsafe(24),
        "REVERB_APP_SECRET": secrets.token_urlsafe(32),
        "REVERB_ALLOWED_ORIGINS": host,
        "OPENAI_REDIRECT_URI": url + "/auth/openai/callback",
        "BI_MCP_REDIRECT_URI": url + "/auth/bi-mcp/callback",
    }
    text = template.read_text()
    for name, value in values.items():
        text, count = re.subn(r"^" + re.escape(name) + r"=.*$", lambda match: name + "=" + value, text, flags=re.MULTILINE)
        if count != 1:
            raise ValueError("Modelo de configuração incompleto ou ambíguo: " + name)
    descriptor = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w") as stream:
            stream.write(text)
            stream.flush()
            os.fsync(stream.fileno())
    except BaseException:
        output.unlink(missing_ok=True)
        raise
    return url


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="https://suite.inovadora.com.br/ia-ganalise")
    parser.add_argument("--port", type=int, default=8089)
    parser.add_argument("--output", type=Path, default=ROOT / ".env.production")
    args = parser.parse_args()
    try:
        url = prepare(ROOT / ".env.production.example", args.output, args.url, args.port)
    except FileExistsError:
        parser.exit(2, "O arquivo já existe. Preserve a APP_KEY e ajuste a configuração existente; nenhum arquivo foi alterado.\n")
    except (ValueError, OSError) as error:
        parser.exit(2, str(error) + "\n")
    print("Configuração nova criada com permissão 0600 em " + str(args.output))
    print("Endereço: " + url + ". Guarde a configuração em backup criptografado antes de iniciar.")
    print("Integração central ChatGPT permanece desabilitada até a configuração oficial. Nenhum serviço foi iniciado.")


if __name__ == "__main__":
    main()

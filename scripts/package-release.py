#!/usr/bin/env python3
"""Empacota somente o HEAD limpo do Git, sem copiar dados da instalação local."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import subprocess

ROOT = Path(__file__).resolve().parent.parent
PRIVATE_FOLDERS = {".runtime", ".lumo", ".codex", "backups", "reports-private", "node_modules", "vendor"}
ENV_EXAMPLES = {".env.example", ".env.production.example"}


def git(root, *args):
    return subprocess.run(["git", "-C", str(root), *args], check=True, capture_output=True).stdout


def package(root, output):
    output = output.absolute()
    resolved = output.resolve()
    if resolved == root.resolve() or root.resolve() in resolved.parents:
        raise ValueError("Grave o pacote fora do projeto para não misturar código e arquivos de entrega.")
    if not output.name.endswith(".tar.gz"):
        raise ValueError("O pacote deve terminar em .tar.gz.")
    commit = git(root, "rev-parse", "HEAD").decode().strip()
    if git(root, "status", "--porcelain", "--untracked-files=normal").strip():
        raise ValueError("Há alterações pendentes. Revise, teste e faça o commit antes de gerar a entrega.")
    files = []
    for record in git(root, "ls-tree", "-rz", "--full-tree", commit).split(b"\0"):
        if not record:
            continue
        metadata, raw_path = record.split(b"\t", 1)
        mode, kind, identifier = metadata.split(b" ")
        path = raw_path.decode("utf-8")
        parts = PurePosixPath(path).parts
        if mode not in (b"100644", b"100755") or kind != b"blob":
            raise ValueError("Link ou submódulo não permitido no pacote: " + path)
        if (
            not parts or any(part in PRIVATE_FOLDERS for part in parts)
            or (any(part.startswith(".env") for part in parts) and path not in ENV_EXAMPLES)
            or (parts[0] == "storage" and parts[-1] != ".gitignore")
            or PurePosixPath(path).suffix.lower() in {".sqlite", ".sqlite3", ".db", ".pem", ".key", ".p12", ".pfx"}
        ):
            raise ValueError("Arquivo privado ou gerado foi versionado; remova-o antes da entrega: " + path)
        files.append(path)
    if git(root, "rev-parse", "HEAD").decode().strip() != commit:
        raise ValueError("O commit mudou durante a conferência. Gere o pacote novamente.")
    checksum_path = Path(str(output) + ".sha256")
    manifest_path = Path(str(output) + ".json")
    targets = [output, checksum_path, manifest_path]
    if any(path.exists() or path.is_symlink() for path in targets):
        raise FileExistsError("Um arquivo de entrega já existe. Escolha outro nome; nenhum arquivo foi substituído.")
    created = []
    try:
        with os.fdopen(os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as stream:
            created.append(output)
            subprocess.run(["git", "-C", str(root), "archive", "--format=tar.gz", "--prefix=g-analise-ia/", commit], stdout=stream, check=True, stderr=subprocess.PIPE)
        with output.open("rb") as stream:
            hasher = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                hasher.update(chunk)
            digest = hasher.hexdigest()
        manifest = {"product": "G-Analise IA", "commit": commit, "sha256": digest, "source_files": len(files), "contents": "git_head_only", "runtime_files_copied": 0, "deployed": False}
        for path, value in [(checksum_path, digest + "  " + output.name + "\n"), (manifest_path, json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")]:
            with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "w") as stream:
                created.append(path)
                stream.write(value)
        return manifest
    except BaseException:
        for path in created:
            path.unlink(missing_ok=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        manifest = package(ROOT, args.output)
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(2, str(error) + "\n")
    print(json.dumps(manifest, ensure_ascii=False))
    print("Pacote de código criado em " + str(args.output) + ". Segredos e dados devem ser configurados no destino.")


if __name__ == "__main__":
    main()

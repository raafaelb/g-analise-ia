#!/usr/bin/env python3
"""Backup criptografado do portal e ensaio de restauração em banco separado."""
import argparse
import fcntl
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
TAG = "g-analise-ia"


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def interrupted(signum, frame):
    raise KeyboardInterrupt


def main():
    os.umask(0o077)
    signal.signal(signal.SIGTERM, interrupted)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["backup", "restore-test", "prune"])
    parser.add_argument("--repository", type=Path, required=True)
    parser.add_argument("--password-file", type=Path, required=True)
    parser.add_argument("--env-file", type=Path, default=ROOT / ".env")
    parser.add_argument("--compose-file", type=Path, default=ROOT / "compose.yaml")
    parser.add_argument("--image", default="restic/restic:0.19.1")
    parser.add_argument("--snapshot", default="latest")
    parser.add_argument("--database", default="horus_ia")
    parser.add_argument("--db-user", default="horus")
    parser.add_argument("--keep-daily", type=int, default=7)
    parser.add_argument("--keep-weekly", type=int, default=4)
    parser.add_argument("--keep-monthly", type=int, default=6)
    parser.add_argument("--apply-retention", action="store_true")
    args = parser.parse_args()
    repository = args.repository.resolve()
    password = args.password_file.resolve()
    env_file = args.env_file.resolve()
    if not password.is_file() or password.stat().st_mode & 0o077:
        parser.error("O arquivo de senha precisa existir e ter permissão 0600.")
    if not env_file.is_file():
        parser.error("O arquivo de configuração não existe.")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_./:@-]+", args.image):
        parser.error("Imagem Docker inválida.")
    if args.snapshot != "latest" and not re.fullmatch(r"[a-f0-9]{8,64}", args.snapshot):
        parser.error("Informe latest ou o hash do snapshot.")
    for identifier in [args.database, args.db_user]:
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]{0,62}", identifier):
            parser.error("Identificador PostgreSQL inválido.")
    if min(args.keep_daily, args.keep_weekly, args.keep_monthly) < 1:
        parser.error("A retenção precisa preservar pelo menos um snapshot por período.")
    repository.mkdir(parents=True, exist_ok=True, mode=0o700)
    staging = ROOT / "backups" / ".staging"
    staging.mkdir(parents=True, exist_ok=True, mode=0o700)
    operation_lock = (staging / "operator.lock").open("a")
    try:
        fcntl.flock(operation_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise RuntimeError("Outra operação de backup/restauração está em andamento.")
    compose = ["docker", "compose", "--env-file", str(env_file), "-f", str(args.compose_file.resolve())]
    uid = f"{os.getuid()}:{os.getgid()}"

    def restic(command, mounts=(), stdin=None):
        docker = ["docker", "run", "--rm", "-i", "--network", "none", "--user", uid,
                  "--mount", f"type=bind,src={repository},dst=/repo",
                  "--mount", f"type=bind,src={password},dst=/run/secrets/password,readonly",
                  "-e", "RESTIC_PASSWORD_FILE=/run/secrets/password"]
        for source, target, readonly in mounts:
            docker += ["--mount", f"type=bind,src={source},dst={target}" + (",readonly" if readonly else "")]
        return run(docker + [args.image, "-r", "/repo", "--no-cache"] + command,
                   stdin=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode("utf-8")

    if args.mode == "backup":
        entered = False
        try:
            state = json.loads(run(compose + ["exec", "-T", "app", "php", "artisan", "ganalise:backup-state"], text=True, capture_output=True).stdout)
            if not state["maintenance"]:
                run(compose + ["exec", "-T", "app", "php", "artisan", "down", "--retry=15"], capture_output=True)
                entered = True
            state = json.loads(run(compose + ["exec", "-T", "app", "php", "artisan", "ganalise:backup-state"], text=True, capture_output=True).stdout)
            if not state["ready"]:
                raise RuntimeError("Há tarefas em andamento; aguarde a conclusão antes do backup.")
            with tempfile.TemporaryDirectory(prefix="ganalise-backup-", dir=staging) as temporary:
                stage = Path(temporary)
                with (stage / "database.dump").open("wb") as target:
                    run(compose + ["exec", "-T", "postgres", "pg_dump", "-U", args.db_user, "-Fc", args.database], stdout=target, stderr=subprocess.PIPE)
                with (stage / "private.tar.gz").open("wb") as target:
                    run(compose + ["exec", "-T", "app", "tar", "-C", "/var/www/html/storage/app",
                                   "--exclude=private/runtime-sessions", "--exclude=private/workspaces/*/catalog-*",
                                   "--exclude=private/codex/*/*/tmp", "--exclude=private/testing", "-czf", "-", "private"], stdout=target, stderr=subprocess.PIPE)
                shutil.copyfile(env_file, stage / "config.env")
                (stage / "config.env").chmod(0o600)
                manifest = {"format": 1, "created_at": datetime.now(timezone.utc).isoformat(), "database": args.database, "state": state}
                (stage / "manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
                if entered:
                    run(compose + ["exec", "-T", "app", "php", "artisan", "up"], capture_output=True)
                    entered = False
                bundle = stage / "snapshot.tar"
                with tarfile.open(bundle, "w") as archive:
                    for name in ["database.dump", "private.tar.gz", "config.env", "manifest.json"]:
                        archive.add(stage / name, arcname=name, recursive=False)
                if not (repository / "config").is_file():
                    restic(["init"])
                with bundle.open("rb") as source:
                    output = restic(["backup", "--stdin", "--stdin-filename", "snapshot.tar", "--tag", TAG+"-pending", "--host", TAG, "--json"], stdin=source)
                summaries = [json.loads(line) for line in output.splitlines() if line.startswith("{")]
                summary = next(item for item in reversed(summaries) if item.get("message_type") == "summary")
                restic(["check", "--read-data"])
                restic(["tag", "--set", TAG, summary["snapshot_id"]])
                snapshots=json.loads(restic(["snapshots", "--json", "--tag", TAG, "--host", TAG, "--latest", "1"]))
                print(json.dumps({"ok": True, "snapshot_id": snapshots[0]["id"], "repository": str(repository), "integrity_checked": True}))
        finally:
            if entered:
                run(compose + ["exec", "-T", "app", "php", "artisan", "up"], capture_output=True)
    elif args.mode == "restore-test":
        scratch = "ganalise_restore_" + uuid.uuid4().hex[:12]
        created = False
        try:
            with tempfile.TemporaryDirectory(prefix="ganalise-restore-", dir=staging) as temporary:
                destination = Path(temporary)
                restic(["restore", args.snapshot, "--tag", TAG, "--host", TAG, "--target", "/restore", "--overwrite", "never", "--verify"], [(destination, "/restore", False)])
                snapshot = destination / "snapshot"
                snapshot.mkdir(mode=0o700)
                with tarfile.open(destination / "snapshot.tar", "r|") as archive:
                    for member in archive:
                        if member.name not in ["manifest.json", "database.dump", "private.tar.gz", "config.env"] or not member.isfile():
                            raise RuntimeError("Conteúdo inesperado no snapshot.")
                        archive.extract(member, snapshot)
                for name in ["manifest.json", "database.dump", "private.tar.gz", "config.env"]:
                    if not (snapshot / name).is_file():
                        raise RuntimeError("Snapshot incompleto: " + name)
                files = snapshot / "files"
                files.mkdir(mode=0o700)
                with tarfile.open(snapshot / "private.tar.gz", "r|gz") as archive:
                    for member in archive:
                        path = PurePosixPath(member.name)
                        if path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != "private":
                            raise RuntimeError("Caminho inválido no arquivo restaurado.")
                        if member.isfile() or member.isdir():
                            archive.extract(member, files)
                run(compose + ["exec", "-T", "postgres", "createdb", "-U", args.db_user, scratch], capture_output=True)
                created = True
                with (snapshot / "database.dump").open("rb") as source:
                    run(compose + ["exec", "-T", "postgres", "pg_restore", "-U", args.db_user, "--no-owner", "--no-privileges", "-d", scratch], stdin=source, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                output = run(compose + ["run", "--rm", "--no-deps", "-T", "-e", "GANALISE_RESTORE_DATABASE=" + scratch,
                                        "-v", f"{snapshot}:/restore:ro", "app", "php", "scripts/verify-restore.php"], text=True, capture_output=True).stdout
                report = json.loads(output.strip())
                if not report.get("ok"):
                    raise RuntimeError("A validação dos dados restaurados falhou.")
                print(json.dumps({**report, "isolated_database": scratch, "original_database_unchanged": True}))
        finally:
            if created:
                run(compose + ["exec", "-T", "postgres", "dropdb", "-U", args.db_user, scratch], capture_output=True)
    else:
        command = ["forget", "--tag", TAG, "--host", TAG, "--keep-daily", str(args.keep_daily),
                   "--keep-weekly", str(args.keep_weekly), "--keep-monthly", str(args.keep_monthly)]
        command += ["--prune"] if args.apply_retention else ["--dry-run"]
        print(restic(command))


if __name__ == "__main__":
    try:
        main()
    except (subprocess.CalledProcessError, RuntimeError, OSError, ValueError, StopIteration) as error:
        # Do not echo subprocess stderr: it can contain database connection details.
        print(json.dumps({"ok": False, "error_type": type(error).__name__, "message": "A operação de backup/restauração não foi concluída. Confira a configuração e o estado das tarefas."}), file=sys.stderr)
        sys.exit(1)

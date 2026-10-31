"""A cópia de segurança preserva dados persistentes e exclui downloads efêmeros."""
import importlib.util
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("ganalise_backup", ROOT / "scripts/backup.py")
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class BackupArchiveTest(unittest.TestCase):
    def test_archive_excludes_temporary_downloads_and_sessions_but_keeps_persistent_exports(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ["private/bi-downloads/writeback.xlsx", "private/runtime-sessions/auth.json",
                         "private/exports/keep.xlsx", "private/attachments/keep.txt"]:
                file = root / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(b"synthetic content")

            result = subprocess.run(["tar"] + backup.private_tar_arguments(root), check=True, capture_output=True)
            with tarfile.open(fileobj=io.BytesIO(result.stdout), mode="r:gz") as archive:
                names = archive.getnames()

            self.assertNotIn("private/bi-downloads/writeback.xlsx", names)
            self.assertNotIn("private/runtime-sessions/auth.json", names)
            self.assertIn("private/exports/keep.xlsx", names)
            self.assertIn("private/attachments/keep.txt", names)

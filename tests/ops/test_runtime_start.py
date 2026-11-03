"""Inicialização não pode depender da cópia inicial do volume Docker."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class RuntimeStartTest(unittest.TestCase):
    def test_empty_mount_is_initialized_without_replacing_existing_private_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            private = root / "storage/app/private"
            private.mkdir(parents=True)
            sentinel = private / "existing.txt"
            sentinel.write_text("preserve existing data")
            command = ["sh", str(ROOT / "docker/start.sh"), "python3", "-c", "from pathlib import Path; import json; p=Path('storage/logs/probe.txt'); p.write_text('synthetic'); print(json.dumps({'mode':p.stat().st_mode & 0o777}))"]
            result = subprocess.run(command, cwd=root, capture_output=True, text=True, check=True)
            self.assertEqual(0o640, json.loads(result.stdout)["mode"])
            for path in ("storage/app/private", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs", "bootstrap/cache"):
                self.assertTrue((root / path).is_dir())
            self.assertEqual("preserve existing data", sentinel.read_text())
            self.assertFalse((root / ".env").exists())
            self.assertFalse((root / ".env.production").exists())
            subprocess.run(command, cwd=root, capture_output=True, text=True, check=True)
            self.assertEqual("preserve existing data", sentinel.read_text())

    def test_invalid_storage_stops_before_the_application_command(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "storage").mkdir()
            (root / "storage/logs").write_text("incorrect mount")
            result = subprocess.run(["sh", str(ROOT / "docker/start.sh"), "python3", "-c", "from pathlib import Path; Path('started').touch()"], cwd=root, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse((root / "started").exists())
            self.assertEqual("incorrect mount", (root / "storage/logs").read_text())

    def test_application_exit_code_is_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run(["sh", str(ROOT / "docker/start.sh"), "sh", "-c", "exit 17"], cwd=directory, capture_output=True)
            self.assertEqual(17, result.returncode)

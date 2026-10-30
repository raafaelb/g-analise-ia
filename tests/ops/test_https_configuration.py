"""Regressão: a APP_KEY não pode substituir a chave pública do Reverb."""
import base64
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class HttpsConfigurationTest(unittest.TestCase):
    def test_fresh_configuration_keeps_keys_distinct_and_stable(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "scripts").mkdir()
            shutil.copyfile(ROOT / "scripts/prepare-https-test.py", root / "scripts/prepare-https-test.py")
            shutil.copyfile(ROOT / ".env.production.example", root / ".env.production.example")
            script = ["python3", str(root / "scripts/prepare-https-test.py")]
            subprocess.run(script, check=True, capture_output=True)
            text = (root / ".env.homologacao").read_text()
            values = dict(line.split("=", 1) for line in text.splitlines() if "=" in line)
            self.assertEqual("homologacao-local", values["REVERB_APP_KEY"])
            self.assertNotEqual(values["APP_KEY"], values["REVERB_APP_KEY"])
            self.assertEqual(32, len(base64.b64decode(values["APP_KEY"].removeprefix("base64:"))))
            self.assertEqual(0, (root / ".env.homologacao").stat().st_mode & 0o077)
            subprocess.run(script, check=True, capture_output=True)
            self.assertEqual(text, (root / ".env.homologacao").read_text())

"""Configuração nova e entrega apenas do código versionado."""
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"), ROOT / "scripts" / (name + ".py"))
    instance = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(instance)
    return instance


class ProductionConfigurationTest(unittest.TestCase):
    def test_new_configuration_has_independent_secrets_and_correct_public_paths(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env.production"
            url = module("prepare-production").prepare(ROOT / ".env.production.example", target, "https://suite.example.test/ia-ganalise/", 8094)
            values = dict(line.split("=", 1) for line in target.read_text().splitlines() if "=" in line and not line.startswith("#"))
            self.assertEqual("https://suite.example.test/ia-ganalise", url)
            self.assertEqual(url, values["APP_URL"])
            self.assertEqual(url, values["ASSET_URL"])
            self.assertEqual("/ia-ganalise", values["PUBLIC_PATH"])
            self.assertEqual("/ia-ganalise", values["SESSION_PATH"])
            self.assertEqual("8094", values["APP_PORT"])
            self.assertEqual(url + "/auth/openai/callback", values["OPENAI_REDIRECT_URI"])
            self.assertEqual(url + "/auth/bi-mcp/callback", values["BI_MCP_REDIRECT_URI"])
            self.assertEqual(32, len(base64.b64decode(values["APP_KEY"].removeprefix("base64:"))))
            self.assertEqual(4, len({values[name] for name in ("APP_KEY", "DB_PASSWORD", "REVERB_APP_KEY", "REVERB_APP_SECRET")}))
            self.assertEqual(0, target.stat().st_mode & 0o077)
            self.assertEqual("false", values["OPENAI_SIGN_IN_ENABLED"])
            self.assertEqual("hosted", values["OPENAI_AUTH_MODE"])
            original = target.read_bytes()
            with self.assertRaises(FileExistsError):
                module("prepare-production").prepare(ROOT / ".env.production.example", target, url, 8094)
            self.assertEqual(original, target.read_bytes())

    def test_invalid_addresses_and_ports_do_not_create_configuration(self):
        prepare = module("prepare-production").prepare
        cases = [(url, 8094) for url in ["http://suite.example.test/ia", "https://user:pass@suite.example.test/ia", "https://suite.example.test/ia?key=secret", "https://suite.example.test/ia#fragment", "https://suite.example.test:8443/ia", "https://127.0.0.1/ia", "https://localhost/ia", "https://suite.example.test/../ia", "https://suite.example.test//ia", "https://suite.example.test/ia%0AAPP_DEBUG=true", "https://suite.example.test\nAPP_DEBUG=true", "https://suite.example.test/ia\\test"]]
        cases += [("https://suite.example.test/ia", 80), ("https://suite.example.test/ia", 65536)]
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env.production"
            for url, port in cases:
                with self.subTest(url=url, port=port), self.assertRaises(ValueError):
                    prepare(ROOT / ".env.production.example", target, url, port)
                self.assertFalse(target.exists())

    def test_root_installation_uses_root_session_path_and_cli_does_not_print_secrets(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env.production"
            result = subprocess.run(["python3", str(ROOT / "scripts/prepare-production.py"), "--url", "https://bi.example.test", "--output", str(target)], check=True, capture_output=True, text=True)
            values = dict(line.split("=", 1) for line in target.read_text().splitlines() if "=" in line and not line.startswith("#"))
            self.assertEqual("", values["PUBLIC_PATH"])
            self.assertEqual("/", values["SESSION_PATH"])
            for name in ("APP_KEY", "DB_PASSWORD", "REVERB_APP_KEY", "REVERB_APP_SECRET"):
                self.assertNotIn(values[name], result.stdout + result.stderr)


class ReleasePackageTest(unittest.TestCase):
    def repository(self, root, filename="README.md", content="synthetic source"):
        env = dict(os.environ, GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_NOSYSTEM="1")
        def git(*args):
            return subprocess.run(["git", "-c", "core.hooksPath=/dev/null", "-c", "commit.gpgsign=false", "-c", "user.email=qa@example.invalid", "-c", "user.name=QA", "-C", str(root), *args], check=True, capture_output=True, env=env)
        git("init", "--quiet")
        target = root / filename
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)
        git("add", "--", filename)
        git("commit", "--quiet", "-m", "synthetic fixture")
        return git

    def test_package_contains_only_committed_source_and_checksum_matches(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "source"
            root.mkdir()
            git = self.repository(root)
            (root / ".gitignore").write_text(".env.production\n.runtime/\nbackups/\n")
            git("add", ".gitignore")
            git("commit", "--quiet", "-m", "ignore private runtime")
            (root / ".env.production").write_text("PRIVATE_SENTINEL")
            (root / ".runtime").mkdir()
            (root / ".runtime/private.json").write_text("PRIVATE_SENTINEL")
            (root / "backups").mkdir()
            (root / "backups/database.sql").write_text("PRIVATE_SENTINEL")
            output = Path(directory) / "release.tar.gz"
            manifest = module("package-release").package(root, output)
            with tarfile.open(output) as archive:
                self.assertEqual(["g-analise-ia", "g-analise-ia/.gitignore", "g-analise-ia/README.md"], archive.getnames())
                self.assertEqual(b"synthetic source", archive.extractfile("g-analise-ia/README.md").read())
            digest = hashlib.sha256(output.read_bytes()).hexdigest()
            self.assertEqual(digest, manifest["sha256"])
            self.assertEqual(manifest, json.loads(Path(str(output) + ".json").read_text()))
            self.assertEqual(digest + "  release.tar.gz\n", Path(str(output) + ".sha256").read_text())
            self.assertEqual(0, output.stat().st_mode & 0o077)
            original = output.read_bytes()
            with self.assertRaises(FileExistsError):
                module("package-release").package(root, output)
            self.assertEqual(original, output.read_bytes())

    def test_committed_public_vendor_assets_are_source_and_not_composer_runtime(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "source"
            root.mkdir()
            git = self.repository(root)
            (root / "public/vendor").mkdir(parents=True)
            (root / "public/vendor/driverjs-LICENSE.txt").write_text("synthetic license")
            git("add", "public/vendor/driverjs-LICENSE.txt")
            git("commit", "--quiet", "-m", "public static source")
            output = Path(directory) / "release.tar.gz"
            module("package-release").package(root, output)
            with tarfile.open(output) as archive:
                self.assertEqual(b"synthetic license", archive.extractfile("g-analise-ia/public/vendor/driverjs-LICENSE.txt").read())

    def test_dirty_source_is_not_packaged(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "source"
            root.mkdir()
            self.repository(root)
            (root / "README.md").write_text("unreviewed edit")
            output = Path(directory) / "release.tar.gz"
            with self.assertRaisesRegex(ValueError, "alterações pendentes"):
                module("package-release").package(root, output)
            self.assertFalse(output.exists())

    def test_tracked_private_files_and_links_are_rejected(self):
        for filename in (".env.production", "storage/app/private/auth.json", ".runtime/github/token.json", "backups/dump.sql", "reports-private/check.json", "database/database.sqlite", "docker/tls.key", "vendor/composer/installed.php"):
            with self.subTest(filename=filename), tempfile.TemporaryDirectory() as directory:
                root = Path(directory) / "source"
                root.mkdir()
                self.repository(root, filename, "PRIVATE_SENTINEL")
                output = Path(directory) / "release.tar.gz"
                with self.assertRaises(ValueError):
                    module("package-release").package(root, output)
                self.assertFalse(output.exists())
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "source"
            root.mkdir()
            git = self.repository(root)
            (root / "link").symlink_to("/etc/passwd")
            git("add", "link")
            git("commit", "--quiet", "-m", "synthetic link")
            with self.assertRaisesRegex(ValueError, "Link ou submódulo"):
                module("package-release").package(root, Path(directory) / "release.tar.gz")

    def test_output_inside_project_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.repository(root)
            with self.assertRaisesRegex(ValueError, "fora do projeto"):
                module("package-release").package(root, root / "release.tar.gz")


class HttpsRehearsalConfigurationTest(unittest.TestCase):
    def fixture(self, root):
        (root / ".env.production.example").write_text((ROOT / ".env.production.example").read_text())
        certificates = root / "backups/homologacao-local"
        certificates.mkdir(parents=True)
        for name in ("localhost.crt", "localhost.key"):
            (certificates / name).write_text("synthetic existing certificate")

    def test_new_rehearsal_disables_all_external_integrations_and_keeps_private_file_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root)
            preparation = module("prepare-https-test")

            with patch.object(preparation.subprocess, "run", return_value=SimpleNamespace(returncode=0)):
                preparation.prepare(root)

            values = dict(line.split("=", 1) for line in (root / ".env.homologacao").read_text().splitlines() if "=" in line and not line.startswith("#"))
            for name in ("OPENAI_SIGN_IN_ENABLED", "OPENAI_PLAN_USAGE_ENABLED", "LUMO_LOGIN_ENABLED", "BI_VIEWER_ENABLED", "SUITE_AUTH_ENABLED", "BI_MCP_ENABLED"):
                self.assertEqual("false", values[name], name)
            self.assertEqual("https://localhost:8443/ia-ganalise", values["APP_URL"])
            target = root / ".env.homologacao"
            original = target.read_bytes()
            target.chmod(0o644)
            with patch.object(preparation.subprocess, "run", return_value=SimpleNamespace(returncode=0)):
                preparation.prepare(root)
            self.assertEqual(original, target.read_bytes())
            self.assertEqual(0, (root / ".env.homologacao").stat().st_mode & 0o077)

    def test_existing_rehearsal_disables_duplicate_external_flags_without_rotating_keys(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root)
            target = root / ".env.homologacao"
            target.write_text("APP_KEY=existing-synthetic-key\nDB_PASSWORD=existing-synthetic-password\nSUITE_AUTH_ENABLED=true\nSUITE_AUTH_ENABLED=1\nBI_MCP_ENABLED=true\n")
            preparation = module("prepare-https-test")

            with patch.object(preparation.subprocess, "run", return_value=SimpleNamespace(returncode=0)):
                preparation.prepare(root)

            values = target.read_text().splitlines()
            self.assertIn("APP_KEY=existing-synthetic-key", values)
            self.assertIn("DB_PASSWORD=existing-synthetic-password", values)
            self.assertEqual(2, values.count("SUITE_AUTH_ENABLED=false"))
            self.assertIn("BI_MCP_ENABLED=false", values)
            self.assertIn("LUMO_LOGIN_ENABLED=false", values)
            self.assertEqual(0, target.stat().st_mode & 0o077)


    def test_rehearsal_does_not_change_configuration_reached_through_a_symbolic_link(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root)
            outside = root / "synthetic-existing-configuration"
            outside.write_text("SUITE_AUTH_ENABLED=true\nAPP_KEY=preserve-synthetic-key\n")
            original = outside.read_bytes()
            (root / ".env.homologacao").symlink_to(outside)

            with self.assertRaisesRegex(ValueError, "sem link simbólico"):
                module("prepare-https-test").prepare(root)

            self.assertEqual(original, outside.read_bytes())

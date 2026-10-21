import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location('public_export', Path(__file__).resolve().parents[2] / 'scripts/export-public-source.py')
public_export = importlib.util.module_from_spec(spec)
spec.loader.exec_module(public_export)


class PublicSourceExportTest(unittest.TestCase):
    def test_private_material_and_production_workflows_are_not_selected(self):
        for path in ['.env', '.env.production', '.github/workflows/deploy.yml',
                     'docs/server-readiness.md', 'docs/plans/server.md',
                     'storage/app/private/credential.json', 'database/database.sqlite',
                     'bootstrap/cache/config.php', '.local/secret.json', '.git/config',
                     'config/secret.pem', 'resources/signature.key',
                     'scripts/offsite-backup.py', 'ops/teg-approval-offsite-backup.service']:
            with self.subTest(path=path):
                self.assertFalse(public_export.included(path))
        self.assertFalse(public_export.included('scripts/new-unreviewed-operations.py'))
        self.assertFalse(public_export.included('ops/new-server.service'))
        self.assertFalse(public_export.included('scripts/private/init-local-env.mjs'))
        self.assertFalse(public_export.included('tests/Deployment/private/deploy-test.sh'))

    def test_known_secret_shapes_are_rejected_without_disclosing_value(self):
        token = b'987654321:' + b'x' * 35
        with self.assertRaises(ValueError) as failure:
            public_export.inspect_bytes('config/demo.php', token)
        self.assertNotIn(token.decode(), str(failure.exception))
        with self.assertRaises(ValueError):
            public_export.inspect_bytes('.env.example', b'DB_PASSWORD=actual-password')

    def test_nested_document_content_is_checked(self):
        import io
        document = io.BytesIO()
        with zipfile.ZipFile(document, 'w') as archive:
            archive.writestr('word/document.xml', b'987654321:' + b'x' * 35)
        with self.assertRaises(ValueError):
            public_export.inspect_bytes('resources/template.docx', document.getvalue())

    def test_export_has_manifest_and_never_overwrites_a_previous_archive(self):
        base = Path(__file__).resolve().parents[2] / 'storage/framework/testing'
        base.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(dir=base) as directory:
            output = Path(directory) / 'source.zip'
            fixture = {'artisan': b'fixture\r\n', 'resources/demo.png': b'\x89PNG\r\n\x1a\n'}
            with patch.object(public_export, 'source_files', return_value=fixture):
                public_export.export(Path(directory), output)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(set(archive.namelist()), {'artisan', 'resources/demo.png', 'PUBLIC_RELEASE.json'})
                self.assertEqual((archive.getinfo('artisan').external_attr >> 16) & 0o777, 0o755)
                self.assertEqual(archive.read('artisan'), b'fixture\n')
                self.assertEqual(archive.read('resources/demo.png'), fixture['resources/demo.png'])
                manifest = json.loads(archive.read('PUBLIC_RELEASE.json'))['sha256']
                for name, digest in manifest.items():
                    self.assertEqual(hashlib.sha256(archive.read(name)).hexdigest(), digest)
            previous = output.read_bytes()
            with self.assertRaises(ValueError):
                public_export.export(Path(directory), output)
            self.assertEqual(output.read_bytes(), previous)


if __name__ == '__main__':
    unittest.main()

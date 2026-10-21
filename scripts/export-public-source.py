#!/usr/bin/env python3
"""Export reviewed source paths, never the working directory or Git history."""

import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile


ROOT_FILES = {
    '.dockerignore', '.editorconfig', '.env.example', '.gitattributes', '.gitignore',
    '.prettierignore', '.prettierrc', 'LICENSE', 'README.md', 'CONTRIBUTING.md',
    'SECURITY.md', 'artisan', 'components.json', 'composer.json', 'composer.lock',
    'package.json', 'package-lock.json', 'phpunit.xml', 'pint.json',
    'eslint.config.js', 'tsconfig.json', 'vite.config.ts', 'Dockerfile',
    'docker-compose.yml', 'docker-compose.production.yml',
    'docker-compose.rehearsal.yml', 'docker-compose.tunnel.yml',
    'deploy.sh', 'setup-server.sh',
}
SOURCE_DIRS = {'app', 'config', 'database', 'docker', 'lang', 'ops', 'resources', 'routes', 'scripts', 'tests'}
PUBLIC_DOCS = {'docs/self-hosting.md', 'docs/ui-patterns.md'}
PUBLIC_SCRIPTS = {
    'atomic-link.py', 'backup.sh', 'build_management_document_template.py',
    'check-release-nginx.py', 'clean_signature.py', 'configure-private-storage.sh',
    'expand-ubuntu-root.sh', 'github-deploy.sh', 'init-local-env.mjs',
    'install-production-services.sh', 'online-backup.py', 'operations-common.sh',
    'prepare-production.php', 'production-backup.sh', 'release-health.php',
    'restore.sh', 'export-public-source.py', 'public-release-ci.yml',
}
PUBLIC_DEPLOYMENT_TESTS = {
    'backup-restore-test.sh', 'deploy-test.sh', 'expand-storage-test.sh',
    'github-deploy-test.sh', 'participant-deactivation-race.php',
    'production-configuration-test.php', 'test_release_operations.py',
    'test_public_source_export.py',
}
PRIVATE_PATHS = {
    'scripts/offsite-backup.py',
    'tests/Deployment/test_offsite_backup.py',
    'ops/teg-approval-offsite-backup.service',
}
PUBLIC_ASSETS = {'public/.htaccess', 'public/index.php', 'public/robots.txt',
                 'public/favicon.ico', 'public/favicon.svg', 'public/apple-touch-icon.png'}
SOURCE_SUFFIXES = {'.php', '.ts', '.js', '.mjs', '.vue', '.css', '.json', '.xml',
                   '.yml', '.yaml', '.sh', '.py', '.conf', '.ini', '.service',
                   '.timer', '.svg', '.png', '.jpg', '.jpeg', '.ico', '.docx'}
# Exact synthetic tokens in redaction regression tests; all other tokens block export.
TEST_TOKEN_HASHES = {
    'tests/Unit/ObservabilityTest.php': {'a46caf047260b459be92e01c4172eda84327053ba205b54405ddae03fd4985dd'},
    'tests/Unit/SystemLogReaderTest.php': {'08b9ecd2830bb0e0f48e7ac405abbad5c77eb8974c3ecfda3e57540e47e20ea1'},
    'tests/Feature/TelegramBroadcastTest.php': {'a7d6bbc051c3cc8ea7467e8cd7dda9ed019dcd6158c304b97582eaef5875d4ae'},
}
SECRET_PATTERNS = {
    'private key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
    'GitHub token': rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,})\b',
    'Telegram token': rb'\b[0-9]{8,12}:[A-Za-z0-9_-]{30,}\b',
    'AWS key': rb'\b(?:AKIA|ASIA)[0-9A-Z]{16}\b',
    'Slack token': rb'\bxox[baprs]-[0-9A-Za-z-]{20,}\b',
    'channel invitation': rb'https://t\.me/\+[A-Za-z0-9_-]+',
}


def included(name):
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts:
        raise ValueError('Unsafe source path')
    if name in PRIVATE_PATHS:
        return False
    if path.parts[0] == 'scripts':
        return len(path.parts) == 2 and path.name in PUBLIC_SCRIPTS
    if path.parts[0] == 'ops':
        return name in {'ops/supervisor.conf', 'ops/online-migrations.json'}
    if path.parts[:2] == ('tests', 'Deployment'):
        return len(path.parts) == 3 and path.name in PUBLIC_DEPLOYMENT_TESTS
    if name in ROOT_FILES | PUBLIC_DOCS | PUBLIC_ASSETS:
        return True
    if name.startswith(('storage/', 'bootstrap/')):
        return path.name == '.gitignore' or name in {'bootstrap/app.php', 'bootstrap/providers.php'}
    return path.parts[0] in SOURCE_DIRS and path.suffix in SOURCE_SUFFIXES and name != 'scripts/sentry.ps1'


def inspect_bytes(name, data):
    for label, pattern in SECRET_PATTERNS.items():
        for match in re.finditer(pattern, data):
            if label in {'Telegram token', 'channel invitation'} and hashlib.sha256(match.group()).hexdigest() in TEST_TOKEN_HASHES.get(name, set()):
                continue
            raise ValueError(f'{label} detected in {name}; value withheld')
    if name.endswith('.docx'):
        with zipfile.ZipFile(io.BytesIO(data)) as document:
            for part in document.namelist():
                if part.endswith('.xml'):
                    inspect_bytes(f'{name}:{part}', document.read(part))
    if name == '.env.example':
        for line in data.decode('utf-8').splitlines():
            if line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            value = value.strip().strip('"\'')
            if re.search(r'(TOKEN|PASSWORD|SECRET|APP_KEY|CREDENTIAL)', key) and value and not value.startswith('${'):
                raise ValueError(f'Nonempty credential setting {key} in .env.example')


def source_files(root):
    output = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=root)
    names = sorted(set(output.decode('utf-8').split('\0')) - {''})
    result = {}
    for name in names:
        if not included(name):
            continue
        path = root / name
        if not path.exists():  # Deleted files must not reappear from the index.
            continue
        if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != root):
            raise ValueError(f'Symlink is not allowed: {name}')
        if not path.resolve().is_relative_to(root.resolve()) or not path.is_file():
            raise ValueError(f'Unsafe source: {name}')
        data = path.read_bytes()
        inspect_bytes(name, data)
        result[name] = data
    required = ROOT_FILES | PUBLIC_DOCS | PUBLIC_ASSETS | {'bootstrap/app.php', 'bootstrap/providers.php', 'scripts/init-local-env.mjs'}
    missing = required - result.keys()
    if missing:
        raise ValueError('Missing required files: ' + ', '.join(sorted(missing)))
    # Production workflows and their history are deliberately not copied.
    result['.github/workflows/ci.yml'] = (root / 'scripts/public-release-ci.yml').read_bytes()
    inspect_bytes('.github/workflows/ci.yml', result['.github/workflows/ci.yml'])
    # README must remain in GitHub-generated source archives.
    result['.gitattributes'] = result['.gitattributes'].replace(b'README.md export-ignore', b'# README is part of the public distribution')
    return result


def export(root, output):
    if output.exists():
        raise ValueError('Output already exists; choose a new filename')
    files = source_files(root)
    # Match .gitattributes before hashing, so Git checkouts retain valid manifests.
    binary_suffixes = {'.png', '.jpg', '.jpeg', '.ico', '.docx'}
    files = {
        name: data if PurePosixPath(name).suffix.lower() in binary_suffixes or b'\0' in data
        else data.replace(b'\r\n', b'\n')
        for name, data in files.items()
    }
    manifest = {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())}
    files['PUBLIC_RELEASE.json'] = (json.dumps({'format': 1, 'sha256': manifest}, indent=2) + '\n').encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open('xb') as target, zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in sorted(files.items()):
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100755 if name.endswith('.sh') or name == 'artisan' else 0o100644) << 16
            archive.writestr(info, data)
    return len(files)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path, help='New ZIP file; existing files are never overwritten')
    args = parser.parse_args()
    try:
        count = export(Path(__file__).resolve().parents[1], args.output.resolve())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, f'Export blocked: {error}\n')
    print(f'Exported {count} files without Git history, private docs, production workflows or runtime data.')
    print(args.output.resolve())

#!/usr/bin/env python3
"""Offline static regression checks; live integrations are NOT tested."""
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parent
checks = {}
skips = []

def check(name, ok):
    checks[name] = bool(ok)
    print(('PASS' if ok else 'FAIL') + ' ' + name)

php_files = sorted(root.rglob('*.php'))
php_bin = shutil.which('php')
if php_bin is None:
    skips.append(f'PHP syntax ({len(php_files)} files): PHP executable not installed')
    print('SKIP ' + skips[-1])
else:
    failed = []
    for source in php_files:
        try:
            proc = subprocess.run([php_bin, '-l', str(source)], capture_output=True, text=True)
            if proc.returncode:
                failed.append(str(source.relative_to(root)))
        except OSError as exc:
            failed.append(f'{source.relative_to(root)}: {exc}')
    check(f'PHP syntax: {len(php_files)} files', not failed)
    for file in failed:
        print('  PHP_SYNTAX_ERROR', file)

check('Installer syntax', subprocess.run(['bash', '-n', str(root / 'install.sh')]).returncode == 0)
request = (root / 'request.php').read_text()
check('HTTP helper validates TLS certificate', 'CURLOPT_SSL_VERIFYPEER, true' in request and 'CURLOPT_SSL_VERIFYHOST, 2' in request)
utils = (root / 'api/utils.php').read_text()
check('API log does not store whole request headers', 'json_encode($headers)' not in utils)
check('API log does not store whole request body', 'json_encode($data)' not in utils)
check('Panel login CSRF verified', 'noorVpnValidCsrf(' in (root / 'panel/login.php').read_text())
check('Database migration restricted to CLI', "PHP_SAPI !== 'cli'" in (root / 'table.php').read_text())
check('Cron scripts restricted to CLI', all("PHP_SAPI !== 'cli'" in f.read_text() for f in (root / 'cronbot').glob('*.php')))
check('Backup database password not in command', "-p'$passworddb'" not in (root / 'cronbot/backupbot.php').read_text())
installer = (root / 'install.sh').read_text()
check('Installer avoids destructive MySQL repair', 'rm -rf /var/lib/mysql' not in installer and 'skip-grant-tables\\n' not in installer and "DROP USER IF EXISTS 'root'" not in installer)
check('Sensitive config blocked in Apache', 'FilesMatch' in (root / '.htaccess').read_text())
check('No embedded real database credentials', '{password_db}' in (root / 'config.php').read_text())
print(f'RESULT: {sum(checks.values())}/{len(checks)} executed checks passed; {len(skips)} skipped')
if skips:
    print('NOTE: Install a compatible PHP CLI or run the installer in an isolated test VM to execute PHP syntax checks. Skipped is NOT passed.')
sys.exit(0 if all(checks.values()) else 1)

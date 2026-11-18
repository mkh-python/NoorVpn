from pathlib import Path
import re
import os
import tempfile
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[1]

class InstallerTests(unittest.TestCase):
    def test_help_and_invalid_inputs_do_not_require_deployment(self):
        p=subprocess.run(['bash',str(ROOT/'install.sh'),'--help'],capture_output=True,text=True)
        self.assertEqual(p.returncode,0);self.assertIn('NoorVpn',p.stdout)
        p=subprocess.run(['bash',str(ROOT/'install.sh'),'check','--domain','bad;example'],cwd=ROOT,capture_output=True,text=True)
        self.assertNotEqual(p.returncode,0);self.assertIn('Invalid domain',p.stderr)
    def test_acme_directories_are_traversable_with_private_umask(self):
        source=(ROOT/'install.sh').read_text()
        line=next(line for line in source.splitlines() if line.startswith('install -d -m 0755 ') and '/var/lib/noorvpn-acme/.well-known/acme-challenge' in line)
        with tempfile.TemporaryDirectory() as tmp:
            command=line.replace('/var/www',tmp+'/var/www').replace('/var/lib/noorvpn-acme',tmp+'/var/lib/noorvpn-acme')
            subprocess.run(['bash','-c','umask 077; '+command],check=True)
            base=Path(tmp)/'var/lib/noorvpn-acme'
            for directory in [base,base/'.well-known',base/'.well-known/acme-challenge']:
                self.assertTrue(directory.stat().st_mode & 0o001, str(directory)+' blocks Nginx traversal')
    def test_cron_targets_exist(self):
        s=(ROOT/'deploy/cron.schedule').read_text()
        for line in s.splitlines():
            if line and not line.startswith('#'):
                self.assertTrue((ROOT/'cronbot'/line.split()[-1]).is_file(),line)
    def test_migrations_do_not_change_webhooks_or_ignore_schema_errors(self):
        s=(ROOT/'table.php').read_text()
        self.assertNotIn("telegram('setwebhook'",s)
        self.assertNotRegex(s,r'WHERE table_name = :tableName(?! AND table_schema = DATABASE\(\))')
        self.assertIn('throw $e;',s)
    def test_nginx_runtime_is_private_and_extensionless_api_is_supported(self):
        s=(ROOT/'deploy/nginx.conf.template').read_text()
        self.assertIn('location ^~ /storage/',s)
        self.assertIn('rewrite ^/api/([a-z_]+)$ /api/$1.php last',s)
        self.assertIn('location ~ \\.php$ { return 404; }',s)
    def test_dependency_install_does_not_execute_as_root(self):
        s=(ROOT/'install.sh').read_text()
        self.assertNotIn('COMPOSER_ALLOW_SUPERUSER=1',s)
        self.assertIn('runuser -u nobody',s)
        self.assertIn('--no-plugins --no-scripts',s)
        self.assertIn('mysql.user',s)
        self.assertNotIn('ALTER USER',s)
        self.assertIn('secret_token',s)

    @unittest.skipUnless(os.geteuid()==0, 'runtime ownership check requires root')
    def test_runtime_user_can_write_state_and_cron_log(self):
        line=next(line for line in (ROOT/'install.sh').read_text().splitlines() if line.startswith('install -d -o www-data'))
        with tempfile.TemporaryDirectory() as tmp:
            os.chmod(tmp,0o755)
            subprocess.run(['bash','-c',line],env={**os.environ,'APP_DIR':tmp},check=True)
            result=subprocess.run(['runuser','-u','www-data','--','sh','-c','printf ok > "$1/storage/cron.log"; printf token > "$1/storage/api-token"','sh',tmp],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual((Path(tmp)/'storage/cron.log').read_text(),'ok')
if __name__=='__main__':unittest.main()

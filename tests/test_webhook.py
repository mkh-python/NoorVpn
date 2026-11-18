import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]

class WebhookTests(unittest.TestCase):
    def run_php(self, code, env=None):
        return subprocess.run(['php','-r',code],cwd=ROOT,env={**os.environ,**(env or {})},capture_output=True,text=True)

    def test_secret_is_constant_time_validated_and_fails_closed(self):
        code='require "security.php"; echo json_encode([noorVpnValidWebhook("abc", "abc"),noorVpnValidWebhook("abc", "bad"),noorVpnValidWebhook("", ""),noorVpnValidWebhook("abc", null)]);'
        p=self.run_php(code)
        self.assertEqual(p.returncode,0,p.stderr)
        self.assertEqual(p.stdout,'[true,false,false,false]')

    def test_unauthorized_index_never_bootstraps_database(self):
        p=self.run_php('$_SERVER["REQUEST_METHOD"]="POST"; require "index.php";',{'NOORVPN_WEBHOOK_SECRET_FILE':'/nonexistent/noor-secret'})
        self.assertEqual(p.returncode,0,p.stderr)
        self.assertEqual(p.stdout,'Forbidden')
        self.assertNotIn('database',p.stderr.lower())

    def test_reads_secret_from_private_file(self):
        with tempfile.NamedTemporaryFile(mode='w') as f:
            f.write('test-secret\n');f.flush()
            p=self.run_php('require "security.php"; echo noorVpnWebhookSecret();',{'NOORVPN_WEBHOOK_SECRET_FILE':f.name})
            self.assertEqual(p.returncode,0,p.stderr)
            self.assertEqual(p.stdout,'test-secret')

if __name__=='__main__':unittest.main()

class LoginBoundaryTests(WebhookTests):
    def test_password_hash_itself_cannot_be_used_as_password(self):
        p=self.run_php('require "security.php"; $h=password_hash("correct horse battery staple", PASSWORD_DEFAULT); echo json_encode([noorVpnVerifyPassword("correct horse battery staple",$h),noorVpnVerifyPassword($h,$h),noorVpnVerifyPassword("legacy","legacy"),noorVpnVerifyPassword("","")]);')
        self.assertEqual(p.returncode,0,p.stderr)
        self.assertEqual(p.stdout,'[true,false,true,false]')
    def test_csrf_without_server_session_token_is_rejected(self):
        p=self.run_php('require "security.php"; echo json_encode([noorVpnValidCsrf("",""),noorVpnValidCsrf("abc","abc"),noorVpnValidCsrf("abc",[])]);')
        self.assertEqual(p.returncode,0,p.stderr)
        self.assertEqual(p.stdout,'[false,true,false]')

from pathlib import Path
import subprocess
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
class CertificateTests(unittest.TestCase):
    def test_existing_matching_certificate_can_be_reused(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);cert=root/'cert.pem';key=root/'key.pem'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=bot.example.com','-keyout',str(key),'-out',str(cert)],capture_output=True,check=True)
            command=['bash',str(ROOT/'bin/certificate-ready.sh'),str(cert),str(key)]
            self.assertEqual(subprocess.run(command+['bot.example.com'],capture_output=True).returncode,0)
            self.assertNotEqual(subprocess.run(command+['other.example.com'],capture_output=True).returncode,0)
            self.assertNotEqual(subprocess.run(command[:-1]+[str(root/'missing.key'),'bot.example.com'],capture_output=True).returncode,0)
if __name__=='__main__':unittest.main()

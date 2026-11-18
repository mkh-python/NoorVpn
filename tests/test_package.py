import importlib.util
from pathlib import Path
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
class PackageTests(unittest.TestCase):
    def module(self):
        spec=importlib.util.spec_from_file_location('package_noorvpn',ROOT/'bin/package.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
    def test_runtime_and_private_files_are_excluded(self):
        m=self.module()
        for path in ['.git/config','storage/api-token','vendor/autoload.php','config.local.php','private.pem','error_log','api/hash.txt','tests/__pycache__/test.pyc']:
            self.assertFalse(m.include(Path(path)),path)
        self.assertTrue(m.include(Path('.github/workflows/ci.yml')))
        self.assertTrue(m.include(Path('config.php')))
    def test_real_telegram_token_blocks_packaging(self):
        m=self.module()
        with tempfile.TemporaryDirectory() as d:
            file=Path(d)/'config.php';file.write_text('<?php $APIKEY="123456789:'+('A'*35)+'";')
            with self.assertRaises(ValueError):m.validate(file)
if __name__=='__main__':unittest.main()

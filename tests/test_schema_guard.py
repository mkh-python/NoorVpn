from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
class SchemaGuardTests(unittest.TestCase):
    def test_installed_config_is_rejected_before_any_mysql_command(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'tests').mkdir();shutil.copy2(ROOT/'tests/schema_smoke.py',root/'tests/schema_smoke.py')
            (root/'config.php').write_text('<?php // configured deployment, not a template')
            p=subprocess.run(['/usr/bin/python3',str(root/'tests/schema_smoke.py')],env={'PATH':'/nonexistent'},capture_output=True,text=True)
            self.assertNotEqual(p.returncode,0)
            self.assertIn('Refusing',p.stderr)
            self.assertNotIn('FileNotFoundError',p.stderr)
if __name__=='__main__':unittest.main()

from pathlib import Path
import subprocess
import unittest

class ProtocolFlowTests(unittest.TestCase):
    def test_xui_setup_retries_and_success(self):
        fixture = Path(__file__).with_name('protocol_flow_smoke.php')
        result = subprocess.run(['php', str(fixture)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('successful retry OK', result.stdout)

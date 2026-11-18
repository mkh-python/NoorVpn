import http.server
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
ROOT=Path(__file__).resolve().parents[1]
class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.headers.get('Authorization')!='Bearer fixture-private-token':self.send_response(401);self.end_headers();return
        self.send_response(200);self.end_headers();self.wfile.write(json.dumps({'success':True,'obj':{}}).encode())
    def log_message(self,*args):pass
class PanelCliTests(unittest.TestCase):
    def test_local_check_uses_credential_without_printing_it(self):
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            with tempfile.TemporaryDirectory() as tmp:
                file=Path(tmp)/'panel.json';file.write_text(json.dumps({'url_panel':f'http://127.0.0.1:{server.server_port}','password_panel':'fixture-private-token','resolve':[],'linksubx':'https://sub.example.com/sub','inbounds':[1]}));file.chmod(0o600)
                p=subprocess.run(['php',str(ROOT/'bin/panel.php'),'check','--config',str(file)],capture_output=True,text=True)
                self.assertEqual(p.returncode,0,p.stderr);self.assertIn('OK',p.stdout);self.assertNotIn('fixture-private-token',p.stdout+p.stderr)
                p=subprocess.run(['php',str(ROOT/'bin/panel.php'),'check','--config','panel.json'],cwd=tmp,capture_output=True,text=True)
                self.assertEqual(p.returncode,0,p.stderr)
                file.chmod(0o644)
                p=subprocess.run(['php',str(ROOT/'bin/panel.php'),'check','--config',str(file)],capture_output=True,text=True)
                self.assertNotEqual(p.returncode,0)
        finally:server.shutdown();server.server_close()
if __name__=='__main__':unittest.main()

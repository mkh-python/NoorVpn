import http.server
import json
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        data=json.dumps({'authorization':self.headers.get('Authorization'),'method':self.command}).encode()
        self.send_response(200);self.end_headers();self.wfile.write(data)
    def do_POST(self):
        data=json.dumps({'method':self.command,'body':self.rfile.read(int(self.headers.get('Content-Length',0))).decode()}).encode()
        self.send_response(200);self.end_headers();self.wfile.write(data)
    def log_message(self,*args):pass

class TransportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        cls.thread=threading.Thread(target=cls.server.serve_forever,daemon=True);cls.thread.start()
    @classmethod
    def tearDownClass(cls):cls.server.shutdown();cls.server.server_close()
    def invoke(self,code):
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp,'config.php').write_text('<?php $request_exec_timeout=1000;')
            p=subprocess.run(['php','-r',f'require {json.dumps(str(ROOT/"request.php"))}; '+code],cwd=tmp,capture_output=True,text=True)
            self.assertEqual(p.returncode,0,p.stderr)
            return json.loads(p.stdout)
    def test_existing_get_and_post_contract(self):
        url=f'http://127.0.0.1:{self.server.server_port}'
        r=self.invoke(f'$r=new CurlRequest("{url}");$r->setBearerToken("fixture");echo json_encode($r->get());')
        self.assertEqual(r['status'],200);self.assertEqual(json.loads(r['body'])['authorization'],'Bearer fixture')
        r=self.invoke(f'$r=new CurlRequest("{url}");echo json_encode($r->post(["a"=>"x y"]));')
        self.assertEqual(json.loads(r['body'])['body'],'a=x+y')
    def test_local_dns_override_keeps_hostname(self):
        url=f'http://noorvpn.invalid:{self.server.server_port}'
        r=self.invoke(f'$r=new CurlRequest("{url}");$r->setResolve(["noorvpn.invalid:{self.server.server_port}:127.0.0.1"]);echo json_encode($r->get());')
        self.assertEqual(r['status'],200,r)
    def test_network_failure_is_structured(self):
        r=self.invoke('$r=new CurlRequest("http://127.0.0.1:1");echo json_encode($r->get());')
        self.assertIsNone(r['status']);self.assertTrue(r['error'])

if __name__=='__main__':unittest.main()

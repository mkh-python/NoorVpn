import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from test_transport import TransportTests
ROOT=Path(__file__).resolve().parents[1]
class XuiTests(TransportTests):
    def invoke_xui(self,call):
        with tempfile.TemporaryDirectory() as tmp:
            for name in ['x-ui_single.php','request.php']:shutil.copy2(ROOT/name,Path(tmp)/name)
            Path(tmp,'config.php').write_text('<?php $request_exec_timeout=1000;')
            panel={'url_panel':f'http://127.0.0.1:{self.server.server_port}','password_panel':'fixture','conecton':'offconecton','on_hold_test':'0'}
            code='require "x-ui_single.php"; $panel=json_decode('+json.dumps(json.dumps(panel))+',true);echo json_encode('+call+');'
            p=subprocess.run(['php','-r',code],cwd=tmp,capture_output=True,text=True)
            self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
    def test_attach_uses_json_object_not_form_encoded_array(self):
        r=self.invoke_xui('attach_service($panel,"fixture",[1,2])')
        body=json.loads(r['body'])['body']
        self.assertEqual(json.loads(body),{'inboundIds':[1,2]})
    def test_create_client_uses_milliseconds_and_byte_quota(self):
        r=self.invoke_xui('addClient($panel,"fixture",2000000000,"subtest",1048576,"[1]","standard")')
        data=json.loads(json.loads(r['body'])['body'])
        self.assertEqual(data['client']['expiryTime'],2000000000000)
        self.assertEqual(data['client']['totalGB'],1048576)
        self.assertEqual(data['inboundIds'],[1])
    def test_partial_update_supplies_required_email(self):
        r=self.invoke_xui('updateClient($panel,"fixture",["enable"=>false])')
        data=json.loads(json.loads(r['body'])['body'])
        self.assertEqual(data,{'email':'fixture','enable':False})
if __name__=='__main__':unittest.main()

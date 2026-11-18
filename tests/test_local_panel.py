import importlib.util
import unittest
import sqlite3
import tempfile
from unittest.mock import patch
import subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class LocalPanelTests(unittest.TestCase):
    def load(self):
        spec=importlib.util.spec_from_file_location('prepare_panel',ROOT/'bin/prepare-local-panel.py')
        m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
    def test_tls_hostname_is_preserved_for_loopback(self):
        m=self.load()
        result=m.panel_configuration({'webPort':'29278','webBasePath':'/private/','webCertFile':'fixture','subEnable':'true','subPort':'2096','subPath':'/sub/','subCertFile':'fixture'},'vpn.example.com','fixture-token',[1])
        self.assertEqual(result['url_panel'],'https://vpn.example.com:29278/private')
        self.assertEqual(result['resolve'],['vpn.example.com:29278:127.0.0.1'])
        self.assertEqual(result['linksubx'],'https://vpn.example.com:2096/sub')
        self.assertEqual(result['inbounds'],[1])
    def test_no_supported_inbound_is_rejected(self):
        with self.assertRaises(ValueError): self.load().panel_configuration({'webPort':'2053'},'vpn.example.com','token',[])
    def test_failed_token_command_removes_empty_output_reservation(self):
        module=self.load()
        with tempfile.TemporaryDirectory() as tmp:
            db=Path(tmp)/'panel.db';output=Path(tmp)/'private.json'
            con=sqlite3.connect(db)
            con.executescript("CREATE TABLE settings(key TEXT,value TEXT); CREATE TABLE inbounds(id INTEGER,protocol TEXT,enable INTEGER); INSERT INTO inbounds VALUES(1,'vless',1); CREATE TABLE api_tokens(name TEXT);")
            con.close()
            argv=['prepare-local-panel.py','--database',str(db),'--output',str(output),'--create-token']
            with patch.object(module.sys,'argv',argv), patch.object(module.os,'geteuid',return_value=0), patch.object(module.subprocess,'run',return_value=subprocess.CompletedProcess([],1,stdout='failed',stderr='')):
                self.assertEqual(module.main(),1)
            self.assertFalse(output.exists())
if __name__=='__main__':unittest.main()

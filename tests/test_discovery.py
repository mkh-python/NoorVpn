import importlib.util
import json
import sqlite3
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class DiscoveryTests(unittest.TestCase):
    def load(self):
        spec = importlib.util.spec_from_file_location('discovery', ROOT / 'bin/discover-panel.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_detects_existing_panel_without_leaking_credentials(self):
        with tempfile.TemporaryDirectory() as folder:
            db = Path(folder) / 'x-ui.db'
            con = sqlite3.connect(db)
            con.executescript('CREATE TABLE settings (key TEXT,value TEXT); CREATE TABLE inbounds (id INTEGER,protocol TEXT,enable INTEGER); CREATE TABLE users (username TEXT,password TEXT);')
            con.executemany('INSERT INTO settings VALUES (?,?)', [('webPort','29278'),('webBasePath','/secret-route/'),('webCertFile','/private/key.pem'),('webKeyFile','TOP-SECRET')])
            con.execute('INSERT INTO users VALUES (?,?)',('admin','PRIVATE-PASSWORD'))
            con.execute("INSERT INTO inbounds VALUES (1,'vless',1)")
            con.commit()
            con.close()
            result = self.load().discover(db)
            self.assertEqual(result['management_port'],29278)
            self.assertTrue(result['tls'])
            self.assertEqual(result['inbounds'],[{'id':1,'protocol':'vless','enabled':True}])
            for secret in ['secret-route','TOP-SECRET','PRIVATE-PASSWORD','key.pem']:
                self.assertNotIn(secret,json.dumps(result))
            self.assertEqual(sqlite3.connect(db).execute('select count(*) from users').fetchone()[0],1)

    def test_missing_database_does_not_create_file(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)/'missing.db'
            with self.assertRaises(FileNotFoundError):
                self.load().discover(path)
            self.assertFalse(path.exists())

    def test_rejects_invalid_port(self):
        with tempfile.TemporaryDirectory() as folder:
            db=Path(folder)/'x.db'
            con=sqlite3.connect(db)
            con.executescript("CREATE TABLE settings(key TEXT,value TEXT); INSERT INTO settings VALUES('webPort','70000'); CREATE TABLE inbounds(id INTEGER,protocol TEXT,enable INTEGER);")
            con.close()
            with self.assertRaises(ValueError): self.load().discover(db)

if __name__ == '__main__': unittest.main()

"""HTTP authentication/isolation checks, only in the temporary schema harness."""
from pathlib import Path
import hashlib
import hmac
import http.client
import json
import socket
import os
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]

def php(code):
    r = subprocess.run(['php', '-r', "require 'config.php';" + code], cwd=ROOT, capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout

marker = os.environ.get('NOORVPN_TEST_DATABASE', '')
if not re.fullmatch(r'noorvpn_test_[0-9a-f]{10}', marker):
    raise SystemExit('Explicit temporary schema harness marker required')
metadata = json.loads(php("echo json_encode(['db'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'fixture'=>$APIKEY==='000000:fixture']);"))
if metadata['db'] != marker or metadata['fixture'] is not True:
    raise SystemExit('Connected database/signing key is not the expected test fixture')
messages = json.loads(php("$l=require 'lang/fa.php';echo json_encode(['funds'=>$l['users']['Balance']['lessThanPrice'],'fulfillment'=>$l['users']['sell']['subscriptionError']]);"))

php('''$u=$pdo->query("SELECT * FROM user WHERE id='987654321'")->fetch();
if(!$u)throw new RuntimeException('Missing schema fixture');
foreach(['991111111'=>11,'992222222'=>22] as $id=>$balance){$r=$u;$r['id']=(string)$id;$r['Balance']=$balance;$r['token']='';$r['User_Status']='Active';$cols=array_keys($r);$q=$pdo->prepare('INSERT INTO user (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');$q->execute(array_values($r));}''')

php("""$p=$pdo->query('SELECT name_panel FROM marzban_panel LIMIT 1')->fetch();
$q=$pdo->prepare('INSERT INTO product (code_product,name_product,price_product,Volume_constraint,Location,Service_time,agent,data_limit_reset,one_buy_status,hide_panel) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute(['miniapp-fixture','Fixture',100,1,$p['name_panel'],1,'f','no_reset','0','[]']);""")
panel_code = php("echo $pdo->query('SELECT code_panel FROM marzban_panel LIMIT 1')->fetchColumn();")

with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]

class PanelHandler(BaseHTTPRequestHandler):
    attempts = []
    def log_message(self, *args):
        pass
    def reply(self, data):
        raw = json.dumps(data).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)
    def do_GET(self):
        self.reply({'success': False, 'msg': 'Obtain: record not found', 'obj': None})
    def do_POST(self):
        data = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))))
        balance = int(php("echo $pdo->query(\"SELECT Balance FROM user WHERE id='991111111'\")->fetchColumn();"))
        self.attempts.append({'path': self.path, 'body': data, 'balance': balance})
        self.reply({'success': False, 'msg': 'fixture-create-failure', 'obj': None})

with tempfile.TemporaryFile() as log:
    panel_server = ThreadingHTTPServer(('127.0.0.1', 0), PanelHandler)
    panel_thread = threading.Thread(target=panel_server.serve_forever, daemon=True)
    panel_thread.start()
    panel_url = 'http://127.0.0.1:' + str(panel_server.server_port)
    php("$pdo->prepare('UPDATE marzban_panel SET url_panel=? WHERE code_panel=?')->execute(" + json.dumps([panel_url, panel_code]) + ");")
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(ROOT)], stdout=log, stderr=log)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Fixture HTTP server did not start')

        def request(path, data=None, token=None):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=15)
            headers = {'Content-Type': 'application/json'}
            if token:
                headers['Authorization'] = 'Bearer ' + token
            conn.request('POST' if data is not None else 'GET', path, None if data is None else json.dumps(data), headers)
            r = conn.getresponse()
            body = r.read()
            status = r.status
            conn.close()
            return status, json.loads(body)

        def init_data(uid, age=0):
            fields = {'auth_date': str(int(time.time()) - age), 'user': json.dumps({'id': uid, 'first_name': 'Fixture'}, separators=(',', ':'))}
            check = '\n'.join(k + '=' + fields[k] for k in sorted(fields))
            secret = hmac.new(b'WebAppData', b'000000:fixture', hashlib.sha256).digest()
            fields['hash'] = hmac.new(secret, check.encode(), hashlib.sha256).hexdigest()
            return urllib.parse.urlencode(fields)

        tokens = []
        for uid in [991111111, 992222222]:
            status, body = request('/api/verify.php', {'initData': init_data(uid)})
            assert status == 200 and body.get('status') and body.get('token'), (status, body)
            tokens.append(body['token'])
        assert tokens[0] != tokens[1]
        status, body = request('/api/miniapp.php?actions=user_info&user_id=992222222', token=tokens[0])
        assert status == 200 and body.get('status') and int(body['obj']['balance']) == 11, (status, body)
        status, body = request('/api/miniapp.php?actions=user_info&user_id=991111111', token=tokens[1])
        assert status == 200 and body.get('status') and int(body['obj']['balance']) == 22, (status, body)
        for token in [None, 'forged-token']:
            status, body = request('/api/miniapp.php?actions=user_info', token=token)
            assert status == 403 and not body.get('status')
        bad = init_data(991111111).replace('hash=', 'hash=00')
        status, body = request('/api/verify.php', {'initData': bad})
        assert status == 403 and not body.get('status')
        status, body = request('/api/verify.php', {'initData': init_data(991111111, 172800)})
        assert status == 403 and not body.get('status')
        purchase = {'actions': 'purchase', 'country_id': panel_code, 'service_id': 'miniapp-fixture'}
        before = php("echo $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();")
        status, body = request('/api/miniapp.php', purchase, tokens[0])
        assert status == 500 and not body.get('status') and body.get('msg') == messages['funds'], (status, body)
        assert not PanelHandler.attempts, 'Insufficient balance reached panel fulfillment'
        status, body = request('/api/miniapp.php?actions=user_info', token=tokens[0])
        assert int(body['obj']['balance']) == 11
        php("$pdo->exec(\"UPDATE product SET price_product='5' WHERE code_product='miniapp-fixture'\");")
        for _ in range(2):
            status, body = request('/api/miniapp.php', purchase, tokens[0])
            assert status == 500 and not body.get('status') and body.get('msg') == messages['fulfillment'], (status, body)
            status, body = request('/api/miniapp.php?actions=user_info', token=tokens[0])
            assert int(body['obj']['balance']) == 11, 'Failed fulfillment did not refund exactly once'
            assert php("echo $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();") == before
        assert len(PanelHandler.attempts) == 2
        assert all(x['path'].endswith('/panel/api/clients/add') and x['balance'] == 6 and x['body']['client']['totalGB'] == 1073741824 for x in PanelHandler.attempts), PanelHandler.attempts
        print('Wallet HTTP: insufficient funds rejected; failed fulfillment retried with exact refund and no orphan invoice OK')
        php("$pdo->exec(\"UPDATE user SET User_Status='block' WHERE id='992222222'\");")
        status, body = request('/api/miniapp.php?actions=user_info', token=tokens[1])
        assert status == 402 and not body.get('status')
        print('Mini App HTTP: signed login, two-user isolation, forged identity/token, expired signature and blocked user OK')
    finally:
        server.terminate()
        server.wait(timeout=10)
        panel_server.shutdown()
        panel_server.server_close()
        panel_thread.join(timeout=5)

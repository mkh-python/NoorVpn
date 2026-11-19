#!/usr/bin/env python3
"""Explicit integration test: creates and removes an isolated MySQL database/user.
Run as a local MySQL administrator, against a throwaway source copy with vendor/.
Never runs against the configured application database.
"""
from pathlib import Path
import os
import json
import tempfile
import threading
import http.server
import secrets
import subprocess
import sys

root=Path(__file__).resolve().parents[1]
name='noorvpn_test_'+secrets.token_hex(5)
password=secrets.token_hex(24)
original=(root/'config.php').read_text()
if not all('{'+key+'}' in original for key in ['database_name','username_db','password_db','API_KEY']):
    sys.exit('Refusing to run schema tests against a configured deployment; use a throwaway source copy.')

def sql(query):
    environment=os.environ.copy()
    if 'NOORVPN_MYSQL_ADMIN_PASSWORD' in environment:environment['MYSQL_PWD']=environment['NOORVPN_MYSQL_ADMIN_PASSWORD']
    result=subprocess.run(['mysql','--protocol=socket','-uroot','-N'],input=query,text=True,capture_output=True,env=environment)
    if result.returncode: raise RuntimeError(result.stderr)
    return result.stdout

try:
    sql(f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{name}'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL ON `{name}`.* TO '{name}'@'localhost';")
    text=original
    for key,value in {'database_url':'localhost','database_name':name,'username_db':name,'password_db':password,'API_KEY':'000000:fixture','admin_number':'123456789','domain_name':'bot.example.invalid','username_bot':'NoorVpnTestBot'}.items():
        text=text.replace('{'+key+'}',value)
    (root/'storage').mkdir(exist_ok=True)
    (root/'config.php').write_text(text)
    for iteration in range(2):
        result=subprocess.run(['php','table.php'],cwd=root,capture_output=True,text=True)
        if result.returncode:
            print(result.stdout);print(result.stderr,file=sys.stderr)
            sys.exit(result.returncode)
        print(f'Schema initialization pass {iteration+1}: OK')
    result=subprocess.run(['php','bin/doctor.php','--database-only'],cwd=root,capture_output=True,text=True)
    print(result.stdout,end='')
    if result.returncode:
        print(result.stderr,file=sys.stderr);sys.exit(result.returncode)
    # The actual panel-registration CLI must persist a working fixture once only.
    from test_panel_cli import Handler
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        with tempfile.NamedTemporaryFile(mode='w') as private:
            json.dump({'url_panel':f'http://127.0.0.1:{server.server_port}','password_panel':'fixture-private-token','linksubx':'https://sub.example.com/sub','inbounds':[1],'resolve':[]},private);private.flush()
            env={**os.environ,'NOORVPN_PANEL_RESOLVE_FILE':str(root/'storage/test-panel-resolve.json')}
            command=['php','bin/panel.php','register','--config',private.name]
            result=subprocess.run(command,cwd=root,capture_output=True,text=True,env=env)
            if result.returncode: raise RuntimeError(result.stderr)
            result=subprocess.run(command,cwd=root,capture_output=True,text=True,env=env)
            if result.returncode==0: raise RuntimeError('Duplicate panel registration was not rejected')
            print('Local panel registration and duplicate guard: OK')
    finally:
        server.shutdown();server.server_close()
        (root/'storage/test-panel-resolve.json').unlink(missing_ok=True)
    # New Telegram users must be insertable in strict MySQL mode, just like index.php.
    query=f"""USE `{name}`; INSERT INTO user (id,step,limit_usertest,User_Status,number,Balance,pagenumber,username,agent,message_count,last_message_time,affiliates,affiliatescount,cardpayment,number_username,namecustom,register,verify,codeInvitation,pricediscount,maxbuyagent,joinchannel,score,status_cron) VALUES ('987654321','none',1,'Active','none',0,1,'fixture','f','0','0','0','0','1','100','none','0','1','abc','0','0','0','0','1'); SELECT COUNT(*) FROM user WHERE id='987654321';"""
    if sql(query).strip()!='1':raise RuntimeError('User registration failed')
    print('Strict-mode user registration: OK')
    result=subprocess.run(['php','tests/libraries_smoke.php'],cwd=root,capture_output=True,text=True)
    print(result.stdout,end='')
    if result.returncode:
        print(result.stderr,file=sys.stderr);sys.exit(result.returncode)

    result=subprocess.run(['python3','tests/miniapp_smoke.py'],cwd=root,capture_output=True,text=True,env={**os.environ,'NOORVPN_TEST_DATABASE':name})
    print(result.stdout,end='')
    if result.returncode:
        print(result.stderr,file=sys.stderr);sys.exit(result.returncode)

    for check in ['tests/report_database.php','tests/report_flow_smoke.php']:
        result=subprocess.run(['php',check],cwd=root,capture_output=True,text=True)
        print(result.stdout,end='')
        if result.returncode:
            print(result.stderr,file=sys.stderr);sys.exit(result.returncode)
finally:
    (root/'config.php').write_text(original)
    sql(f"DROP DATABASE IF EXISTS `{name}`; DROP USER IF EXISTS '{name}'@'localhost';")

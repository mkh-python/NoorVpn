#!/usr/bin/env python3
"""Start a private Nginx instance on loopback and verify PHP cannot leak.
Uses a temporary self-signed certificate; it never reloads the system Nginx.
Requires nginx, openssl, curl; root recommended for distribution include files.
"""
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='noorvpn-nginx-') as directory:
    tmp=Path(directory)
    tmp.chmod(0o755)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    app=tmp/'app';app.mkdir(mode=0o755)
    (app/'api').mkdir();(app/'storage').mkdir()
    (app/'api/users.php').write_text('<?php echo "SECRET_PHP_SOURCE";')
    (app/'config.php').write_text('<?php echo "SECRET_CONFIG";')
    (app/'storage/private.txt').write_text('PRIVATE_STORAGE')
    (app/'safe.css').write_text('body{}')
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=bot.example.com','-keyout',str(tmp/'key.pem'),'-out',str(tmp/'cert.pem')],check=True,capture_output=True)
    template=(ROOT/'deploy/nginx.conf.template').read_text().replace('@HTTPS_PORT@',str(port)).replace('@DOMAIN@','bot.example.com')
    template=template.replace(f'listen {port} ssl;',f'listen 127.0.0.1:{port} ssl;').replace('/var/www/noorvpn',str(app)).replace('/etc/letsencrypt/live/bot.example.com/fullchain.pem',str(tmp/'cert.pem')).replace('/etc/letsencrypt/live/bot.example.com/privkey.pem',str(tmp/'key.pem')).replace('include fastcgi_params;','include /etc/nginx/fastcgi_params;')
    config=tmp/'nginx.conf'
    config.write_text(f'pid {tmp}/nginx.pid; error_log {tmp}/error.log; events {{}} http {{ access_log off; client_body_temp_path {tmp}/body; fastcgi_temp_path {tmp}/fastcgi; proxy_temp_path {tmp}/proxy; scgi_temp_path {tmp}/scgi; uwsgi_temp_path {tmp}/uwsgi; '+template+' }')
    subprocess.run(['nginx','-t','-c',str(config)],check=True,capture_output=True)
    server=subprocess.Popen(['nginx','-c',str(config),'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for attempt in range(30):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.1)
        for path,expected in [('/healthz',200),('/config.php',404),('/storage/private.txt',404),('/api/users',502),('/api/utils',404),('/safe.css',200),('/cronbot/backupbot.php',404),('/vendor/autoload.php',404)]:
            p=subprocess.run(['curl','-k','-sS','--max-time','5','-w','\n%{http_code}',f'https://127.0.0.1:{port}'+path],capture_output=True,text=True,check=True)
            body,_,status=p.stdout.rpartition('\n')
            assert int(status)==expected,(path,status,body)
            assert 'SECRET_' not in body and 'PRIVATE_STORAGE' not in body,path
            print(f'{path}: {status} OK')
    finally:
        server.terminate();server.wait(timeout=10)
print('NoorVpn: isolated Nginx routing/security checks OK')

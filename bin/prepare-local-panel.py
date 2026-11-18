#!/usr/bin/env python3
"""Prepare a private 3x-ui API credential without rotating any existing token.
Requires a modern 3x-ui with named API-token CLI support. Stores secrets only in
an explicitly selected private output file; never prints them.
"""
import argparse
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import subprocess
import sys
from urllib.parse import urlsplit


def panel_configuration(settings, hostname, token, inbounds):
    if settings.get('subEnable','true').lower() != 'true':
        raise ValueError('Panel subscriptions are disabled; enable them in the panel before connecting the bot')
    if not inbounds:
        raise ValueError('No supported active VLESS/VMess/Trojan/Shadowsocks inbound found')
    if not re.fullmatch(r'[a-zA-Z0-9.-]+',hostname):
        raise ValueError('Invalid panel certificate hostname')
    port=int(settings.get('webPort','2053'))
    if not 1<=port<=65535: raise ValueError('Invalid port')
    tls=bool(settings.get('webCertFile'))
    base=settings.get('webBasePath','/').strip('/')
    url=f'{"https" if tls else "http"}://{hostname if tls else "127.0.0.1"}:{port}'
    if base:url+='/'+base
    sub=settings.get('subURI','').strip()
    if not sub:
        subport=int(settings.get('subPort','2096'))
        subhost=settings.get('subDomain') or hostname
        sub=f'{"https" if settings.get("subCertFile") else "http"}://{subhost}:{subport}/'+settings.get('subPath','/sub/').strip('/')
    parts=urlsplit(sub)
    if parts.scheme not in ('http','https') or not parts.hostname:
        raise ValueError('Invalid public subscription URL')
    return {'url_panel':url.rstrip('/'),'password_panel':token,'linksubx':sub.rstrip('/'),'inbounds':inbounds,
            'type':'x-ui_single','resolve':[f'{hostname}:{port}:127.0.0.1'] if tls else []}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database',default='/etc/x-ui/x-ui.db')
    parser.add_argument('--binary',default='/usr/local/x-ui/x-ui')
    parser.add_argument('--output',required=True)
    parser.add_argument('--hostname',help='Hostname covered by the panel TLS certificate')
    parser.add_argument('--create-token',action='store_true',help='Create a NEW named NoorVpn token. Refuses an existing token with that name.')
    args=parser.parse_args()
    if os.geteuid()!=0:parser.error('Run as root')
    output=Path(args.output)
    if not output.is_absolute():parser.error('--output must be an absolute private path')
    if output.exists():parser.error('Output exists; refusing to overwrite')
    if not args.create_token:parser.error('Explicit --create-token is required')
    try:
        with sqlite3.connect(Path(args.database).resolve().as_uri()+'?mode=ro',uri=True) as con:
            con.execute('PRAGMA query_only=ON')
            s=dict(con.execute('SELECT key,value FROM settings'))
            if con.execute("SELECT COUNT(*) FROM api_tokens WHERE name='NoorVpn'").fetchone()[0]:
                raise ValueError('A NoorVpn token already exists; refusing to rotate it')
            ids=[r[0] for r in con.execute("SELECT id FROM inbounds WHERE enable=1 AND protocol IN ('vless','vmess','trojan','shadowsocks') ORDER BY id")]
        hostname=args.hostname or s.get('webDomain')
        if not hostname and s.get('webCertFile'):
            cert=ssl._ssl._test_decode_cert(s['webCertFile'])
            hostname=next(v for k,v in cert.get('subjectAltName',[]) if k=='DNS' and not v.startswith('*'))
        hostname=hostname or 'localhost'
        config=panel_configuration(s,hostname,'',ids)
        # Reserve the destination before creating a token; never discard its plaintext.
        fd=os.open(output,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        token_received=False
        try:
            result=subprocess.run([args.binary,'setting','-getApiToken','-tokenName','NoorVpn','-tokenScope','admin'],capture_output=True,text=True,timeout=30)
            match=re.search(r'^apiToken:\s*(\S+)\s*$',result.stdout,re.M)
            if result.returncode or not match:raise ValueError('3x-ui did not return a token; no existing token was intentionally changed')
            token_received=True
            config['password_panel']=match.group(1)
            with os.fdopen(fd,'w') as file:
                fd=None;json.dump(config,file,ensure_ascii=False,indent=2);file.write('\n');file.flush();os.fsync(file.fileno())
        finally:
            if fd is not None:os.close(fd)
            if not token_received:output.unlink(missing_ok=True)
        print('NoorVpn: private local-panel configuration saved. Existing tokens and clients preserved.')
        return 0
    except (OSError,ValueError,sqlite3.Error,StopIteration,subprocess.SubprocessError) as exc:
        print('NoorVpn: '+str(exc),file=sys.stderr);return 1

if __name__=='__main__':sys.exit(main())

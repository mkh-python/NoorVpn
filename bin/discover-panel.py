#!/usr/bin/env python3
"""Read-only 3x-ui discovery. Never reads user passwords or API tokens."""
import argparse
import json
from pathlib import Path
import sqlite3
import sys


def discover(database):
    database = Path(database).resolve()
    if not database.is_file():
        raise FileNotFoundError('3x-ui database not found')
    with sqlite3.connect(database.as_uri() + '?mode=ro', uri=True) as connection:
        connection.execute('PRAGMA query_only = ON')
        settings = dict(connection.execute(
            "SELECT key,value FROM settings WHERE key IN ('webPort','webListen','webCertFile','webBasePath','subPort','subEnable')"))
        port = int(settings.get('webPort', '2053'))
        if not 1 <= port <= 65535:
            raise ValueError('Invalid panel management port')
        inbounds = [{'id': row[0], 'protocol': row[1], 'enabled': bool(row[2])}
                    for row in connection.execute('SELECT id,protocol,enable FROM inbounds ORDER BY id')]
    return {
        'type': 'x-ui_single',
        'management_port': port,
        'tls': bool(settings.get('webCertFile')),
        'has_base_path': bool(settings.get('webBasePath', '').strip('/')),
        'local_address': '127.0.0.1',
        'subscription_port': int(settings.get('subPort', '2096')),
        'subscription_enabled': settings.get('subEnable', 'false').lower() == 'true',
        'inbounds': inbounds,
        'authentication_required': True,
        'note': 'Use a dedicated API token. Keep the public subscription URL separate. For TLS use the certificate hostname resolved to loopback.',
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database', default='/etc/x-ui/x-ui.db')
    args = parser.parse_args()
    try:
        print(json.dumps(discover(args.database), ensure_ascii=False, indent=2))
    except (OSError, sqlite3.Error, ValueError):
        print('NoorVpn: unable to inspect local 3x-ui database; check path, permissions and schema.', file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__':
    sys.exit(main())

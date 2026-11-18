#!/usr/bin/env python3
"""Create a secret-checked source ZIP; dependencies and runtime data are excluded."""
import argparse
import hashlib
from pathlib import Path
import re
import sys
import zipfile

EXCLUDED={'.git','.superpowers','vendor','storage','__pycache__','.composer','node_modules'}

def include(relative):
    return not (set(relative.parts)&EXCLUDED or relative.name.startswith(('error_log','.env','config.local.'))
                or relative.suffix.lower() in {'.pem','.key','.sql','.db','.sqlite','.sqlite3','.pyc','.zip'}
                or str(relative)=='api/hash.txt')

def validate(path):
    raw=path.read_bytes()
    if re.search(rb'\b[0-9]{6,12}:[a-zA-Z0-9_-]{30,80}\b',raw) or re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',raw):
        raise ValueError('Potential secret detected in '+path.name)
    if path.name=='config.php' and path.parent.name=='NoorVpn' and b'{password_db}' not in raw:
        raise ValueError('Deployment config must never be packaged')

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--output',required=True);args=parser.parse_args()
    root=Path(__file__).resolve().parents[1];target=Path(args.output).resolve()
    if target.is_relative_to(root):parser.error('Write the archive outside the source directory')
    files=[f for f in sorted(root.rglob('*')) if f.is_file() and not f.is_symlink() and include(f.relative_to(root))]
    try:
        if b'{password_db}' not in (root/'config.php').read_bytes():raise ValueError('config.php contains deployment credentials')
        for file in files:validate(file)
        target.parent.mkdir(parents=True,exist_ok=True)
        with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
            for file in files:archive.write(file,'NoorVpn/'+str(file.relative_to(root)))
        digest=hashlib.sha256(target.read_bytes()).hexdigest()
        target.with_suffix(target.suffix+'.sha256').write_text(digest+'  '+target.name+'\n')
        print(f'NoorVpn source archive: {len(files)} files; SHA256 {digest}')
    except (OSError,ValueError) as exc:
        print(str(exc),file=sys.stderr);return 1
    return 0

if __name__=='__main__':sys.exit(main())

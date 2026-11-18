#!/usr/bin/env bash
# NoorVpn: additive installation alongside existing VPN services.
set -Eeuo pipefail
umask 077
log(){ printf '[NoorVpn] %s\n' "$*"; }
die(){ printf '[NoorVpn] ERROR: %s\n' "$*" >&2; exit 1; }
usage(){ cat <<'EOF'
NoorVpn installer (Ubuntu 24.04 / 26.04)
  bash install.sh check --domain bot.example.com
  sudo bash install.sh install --domain bot.example.com --admin 123456789
  sudo bash install.sh status
  sudo bash install.sh discover
Options: --source-path DIR --https-port auto|443|8443 --token-file FILE --dry-run
         --local-panel (detect/connect an existing modern 3x-ui)
         --local-panel-config FILE (use an existing private panel JSON)
         --acme-account ID (choose an existing Certbot account for new issuance)
The bot token is prompted privately if --token-file is omitted.
Existing MTG, SoftEther, 3x-ui and AWG services are preserved.
Existing NoorVpn installations are never overwritten. No automatic upgrades.
EOF
}
ACTION=${1:-help}; (($# == 0)) || shift
[[ $ACTION != --help && $ACTION != -h ]] || { usage; exit 0; }
SOURCE_PATH=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
DOMAIN=''; ADMIN=''; TOKEN_FILE=''; HTTPS_PORT=auto; DRY_RUN=0; LOCAL_PANEL=0; PANEL_CONFIG=''; ACME_ACCOUNT=''
while (($#)); do
 case $1 in
  --source-path|--domain|--admin|--token-file|--https-port|--local-panel-config|--acme-account)
   (($# >= 2)) || die "Missing value for $1"
   case $1 in --source-path) SOURCE_PATH=$2;; --domain) DOMAIN=$2;; --admin) ADMIN=$2;; --token-file) TOKEN_FILE=$2;; --https-port) HTTPS_PORT=$2;; --local-panel-config) PANEL_CONFIG=$2;; --acme-account) ACME_ACCOUNT=$2;; esac
   shift 2;;
  --dry-run) DRY_RUN=1; shift;;
  --local-panel) LOCAL_PANEL=1; shift;;
  --help|-h) usage; exit 0;;
  *) die "Unknown argument: $1";;
 esac
done
case $ACTION in
 help) usage; exit 0;;
 discover) exec python3 "$SOURCE_PATH/bin/discover-panel.py";;
 status) for svc in nginx mysql cron; do printf '%s: ' "$svc"; systemctl is-active "$svc" || :; done; exit 0;;
 check|install) ;;
 *) die 'Unsupported action';;
esac
[[ $DOMAIN =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ && ${#DOMAIN} -le 253 ]] || die 'Invalid domain; supply --domain with the actual bot hostname'
DOMAIN=${DOMAIN,,}
[[ $HTTPS_PORT == auto || $HTTPS_PORT == 443 || $HTTPS_PORT == 8443 ]] || die 'HTTPS port must be auto, 443 or 8443'
SOURCE_PATH=$(realpath -- "$SOURCE_PATH")
for f in config.php composer.lock index.php table.php deploy/nginx.conf.template deploy/cron.schedule; do
 [[ -f $SOURCE_PATH/$f ]] || die "Source file missing: $f"
done
. /etc/os-release
[[ $ID == ubuntu && ( $VERSION_ID == 24.04 || $VERSION_ID == 26.04 ) ]] || die 'Supported operating systems: Ubuntu 24.04 and 26.04'
if [[ $HTTPS_PORT == auto ]]; then
 HTTPS_PORT=443
 if ss -H -ltn '( sport = :443 )' | grep -q .; then HTTPS_PORT=8443; fi
fi
# Refuse ambiguity: do not share or replace an existing TLS listener.
[[ -z $(ss -H -ltn "( sport = :$HTTPS_PORT )") ]] || die "TCP $HTTPS_PORT already occupied"
APP_DIR=/var/www/noorvpn
CONFIG_DIR=/etc/noorvpn
SITE=/etc/nginx/sites-available/noorvpn.conf
CHALLENGE=/etc/nginx/sites-available/noorvpn-acme.conf
CRON=/etc/cron.d/noorvpn
DBNAME=noorvpn; DBUSER=noorvpn
for target in "$APP_DIR" "$CONFIG_DIR" "$SITE" "$CHALLENGE" "$CRON" /etc/nginx/sites-enabled/noorvpn.conf /etc/nginx/sites-enabled/noorvpn-acme.conf; do
 [[ ! -e $target && ! -L $target ]] || die "Existing installation or partial install: $target. Review it before retrying."
done
log "Preflight: Ubuntu $VERSION_ID, HTTPS port $HTTPS_PORT"
df -h /
if [[ -e /etc/x-ui/x-ui.db && -r /etc/x-ui/x-ui.db ]]; then python3 "$SOURCE_PATH/bin/discover-panel.py"; fi
if [[ $ACTION == check || $DRY_RUN == 1 ]]; then log 'Read-only preflight complete'; exit 0; fi
[[ $EUID -eq 0 ]] || die 'Install requires sudo/root'
[[ $ADMIN =~ ^[1-9][0-9]{4,19}$ ]] || die 'Supply a positive Telegram user ID using --admin'
[[ $(df -Pk / | awk 'NR==2 {print $4}') -ge 2097152 ]] || die 'At least 2 GiB free disk required'
if [[ -n $TOKEN_FILE ]]; then
 [[ -f $TOKEN_FILE && ! -L $TOKEN_FILE ]] || die 'Token file must be a regular private file'
 [[ $(stat -c %u "$TOKEN_FILE") == 0 && $(stat -c %a "$TOKEN_FILE") =~ ^[46]00$ ]] || die 'Token file must be owned by root with mode 600 or 400'
 TOKEN=$(< "$TOKEN_FILE")
else
 [[ -t 0 ]] || die 'Use --token-file for noninteractive installation'
 read -r -s -p 'Telegram bot token: ' TOKEN; printf '\n'
fi
[[ $TOKEN =~ ^[0-9]{6,12}:[a-zA-Z0-9_-]{30,80}$ ]] || die 'Invalid Telegram token format'
# Check DNS before package or service changes. DNS proxying is deliberately unsupported.
python3 - "$DOMAIN" <<'PYDNS'
import socket,subprocess,sys
remote={x[4][0] for x in socket.getaddrinfo(sys.argv[1],None,socket.AF_INET)}
local=set(subprocess.check_output(['hostname','-I'],text=True).split())
if not remote or not remote.issubset(local):
 sys.exit('NoorVpn: all IPv4 DNS records must point directly to this server')
PYDNS
STAGE=''; CHALLENGE_ADDED=0; SITE_ADDED=0; SUCCESS=0
cleanup(){
 code=$?
 trap - EXIT
 if [[ $SUCCESS != 1 ]]; then
  [[ $SITE_ADDED == 0 ]] || rm -f -- /etc/nginx/sites-enabled/noorvpn.conf "$SITE"
  [[ $CHALLENGE_ADDED == 0 ]] || rm -f -- /etc/nginx/sites-enabled/noorvpn-acme.conf "$CHALLENGE"
  if [[ $SITE_ADDED == 1 || $CHALLENGE_ADDED == 1 ]]; then nginx -t >/dev/null 2>&1 && systemctl reload nginx || :; fi
  log 'Installation stopped. Any created NoorVpn database/config is retained for inspection; existing VPN services were not reconfigured.' >&2
 fi
 [[ -z $STAGE || ! -d $STAGE ]] || rm -rf -- "$STAGE"
 unset TOKEN DBPASS WEBHOOK_SECRET
 exit "$code"
}
trap cleanup EXIT
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl nginx mysql-server \
 php-fpm php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl php-bcmath php-soap \
 composer certbot cron openssl python3 unzip rsync util-linux
# Validate the token before creating application DB/config or ACME sites.
# Pass the URL through curl's stdin config so it does not appear in process arguments.
ME=$(printf 'url = "https://api.telegram.org/bot%s/getMe"\n' "$TOKEN" | curl --config - --fail --silent --show-error --max-time 20)
USERNAME=$(python3 -c 'import json,sys; x=json.load(sys.stdin); assert x.get("ok"); print(x["result"]["username"])' <<< "$ME")
if [[ $LOCAL_PANEL == 1 ]]; then
 [[ -z $PANEL_CONFIG ]] || die 'Choose --local-panel or --local-panel-config'
 install -d -m 0700 /etc/noorvpn-bootstrap
 PANEL_CONFIG=/etc/noorvpn-bootstrap/panel.json
 if [[ ! -f $PANEL_CONFIG ]]; then
  python3 "$SOURCE_PATH/bin/prepare-local-panel.py" --output "$PANEL_CONFIG" --create-token
 fi
fi
if [[ -n $PANEL_CONFIG ]]; then
 [[ -f $PANEL_CONFIG && ! -L $PANEL_CONFIG ]] || die 'Private panel config not found'
 PANEL_CONFIG=$(realpath -- "$PANEL_CONFIG")
 php "$SOURCE_PATH/bin/panel.php" check --config "$PANEL_CONFIG"
fi

PHP_VER=$(php -r 'if(PHP_VERSION_ID<80200)exit(1);echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
[[ -d /etc/php/$PHP_VER/fpm/pool.d ]] || die 'PHP-FPM version does not match CLI version'
# Reuse valid certificates without selecting or changing old ACME accounts.
CERTIFICATE_READY=0
if bash "$SOURCE_PATH/bin/certificate-ready.sh" "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$DOMAIN"; then
 CERTIFICATE_READY=1
fi
if [[ -n $ACME_ACCOUNT ]]; then
 [[ $ACME_ACCOUNT =~ ^[a-fA-F0-9]{32}$ ]] || die 'Invalid ACME account ID'
fi
if [[ $CERTIFICATE_READY == 0 && -z $ACME_ACCOUNT ]]; then
 ACME_ACCOUNT=$(python3 - "$DOMAIN" <<'PYACCOUNT'
import configparser,sys
from pathlib import Path
p=Path('/etc/letsencrypt/renewal')/(sys.argv[1]+'.conf')
if p.is_file():
 c=configparser.RawConfigParser()
 c.read_string('[certificate]\n'+p.read_text())
 print(c.get('renewalparams','account',fallback=''))
PYACCOUNT
 )
 if [[ -z $ACME_ACCOUNT ]]; then
  account_count=$(find /etc/letsencrypt/accounts/acme-v02.api.letsencrypt.org/directory -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l || :)
  ((account_count <= 1)) || die 'Multiple Certbot accounts exist; provide --acme-account before installation'
 fi
fi
STAGE=$(mktemp -d /var/tmp/noorvpn-stage.XXXXXXXX)
rsync -a --exclude=.git --exclude=vendor --exclude=storage --exclude=error_log --exclude='config.local.*' "$SOURCE_PATH/" "$STAGE/"
# Dependency installation has no panel/database credentials, plugins or scripts.
chown -R nobody:nogroup "$STAGE"
chmod 0750 "$STAGE"
(cd "$STAGE" && runuser -u nobody -- env COMPOSER_HOME="$STAGE/.composer" composer --no-plugins --no-scripts install --no-interaction --no-dev --prefer-dist --optimize-autoloader)
(cd "$STAGE" && runuser -u nobody -- composer --no-plugins --no-scripts check-platform-reqs --no-dev)
rm -rf -- "$STAGE/.composer"
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find "$STAGE" -type f -name '*.php' -print0)
systemctl enable --now mysql
mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null
[[ $(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$DBNAME'") == 0 ]] || die 'NoorVpn database already exists'
[[ $(mysql -uroot -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DBUSER'") == 0 ]] || die 'NoorVpn database user already exists'
DBPASS=$(openssl rand -hex 24)
mysql -uroot <<SQL
CREATE DATABASE \`$DBNAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON \`$DBNAME\`.* TO '$DBUSER'@'localhost';
SQL
DOMAIN_HOST=$DOMAIN; [[ $HTTPS_PORT == 443 ]] || DOMAIN_HOST="$DOMAIN:$HTTPS_PORT"
chown -R root:root "$STAGE"
chmod 0700 "$STAGE"
export DBNAME DBUSER DBPASS TOKEN ADMIN DOMAIN_HOST USERNAME
python3 - "$STAGE/config.php" <<'PYCONFIG'
import os,sys
p=sys.argv[1]; text=open(p).read()
values={'database_url':'localhost','database_name':os.environ['DBNAME'],'username_db':os.environ['DBUSER'],'password_db':os.environ['DBPASS'],'API_KEY':os.environ['TOKEN'],'admin_number':os.environ['ADMIN'],'domain_name':os.environ['DOMAIN_HOST'],'username_bot':os.environ['USERNAME']}
for key,value in values.items(): text=text.replace('{'+key+'}',value.replace('\\','\\\\').replace("'","\\'"))
open(p,'w').write(text)
PYCONFIG
# Staging no longer belongs to the dependency builder once secrets exist.
chown -R root:root "$STAGE"
chmod 0700 "$STAGE"
(cd "$STAGE" && php table.php)
# Migrations must create these core records before the site is exposed.
(cd "$STAGE" && php bin/doctor.php --database-only)
mkdir -m 0750 "$CONFIG_DIR"
chown root:www-data "$CONFIG_DIR"
WEBHOOK_SECRET=$(openssl rand -hex 32)
printf '%s\n' "$WEBHOOK_SECRET" > "$CONFIG_DIR/webhook-secret"
chown root:www-data "$CONFIG_DIR/webhook-secret"; chmod 0640 "$CONFIG_DIR/webhook-secret"
install -d -m 0755 /var/www /var/lib/noorvpn-acme/.well-known/acme-challenge
mv -- "$STAGE" "$APP_DIR"; STAGE=''
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +
install -d -o www-data -g www-data -m 0750 "$APP_DIR/storage" "$APP_DIR/storage/cache" "$APP_DIR/storage/cron" "$APP_DIR/storage/qr"
for runtime_file in images.jpg custom.jpg log.txt; do
 [[ -f $APP_DIR/$runtime_file ]] || touch "$APP_DIR/$runtime_file"
 chown www-data:www-data "$APP_DIR/$runtime_file"
done
if [[ -n $PANEL_CONFIG ]]; then
 php "$APP_DIR/bin/panel.php" register --config "$PANEL_CONFIG"
fi
# Dedicated pool; no edits to an existing application's PHP settings.
cat > "/etc/php/$PHP_VER/fpm/pool.d/noorvpn.conf" <<EOF
[noorvpn]
user = www-data
group = www-data
listen = /run/php/noorvpn.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 15s
pm.max_requests = 300
php_admin_value[memory_limit] = 256M
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/www/noorvpn/storage/php-error.log
php_admin_flag[session.cookie_secure] = on
php_admin_flag[session.cookie_httponly] = on
php_admin_value[session.cookie_samesite] = Lax
EOF
"php-fpm$PHP_VER" -t
systemctl enable --now "php$PHP_VER-fpm" cron
systemctl reload "php$PHP_VER-fpm"
if bash "$APP_DIR/bin/certificate-ready.sh" "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$DOMAIN"; then
 log 'Reusing existing valid domain certificate; existing ACME account and renewal routing are preserved.'
else
cat > "$CHALLENGE" <<EOF
server {
 listen 80;
 server_name $DOMAIN;
 location ^~ /.well-known/acme-challenge/ { root /var/lib/noorvpn-acme; default_type text/plain; }
 location / { return 404; }
}
EOF
CHALLENGE_ADDED=1
ln -s "$CHALLENGE" /etc/nginx/sites-enabled/noorvpn-acme.conf
nginx -t
systemctl is-active --quiet nginx && systemctl reload nginx || systemctl enable --now nginx
ACME_ARGS=()
[[ -z $ACME_ACCOUNT ]] || ACME_ARGS=(--account "$ACME_ACCOUNT")
certbot certonly --webroot -w /var/lib/noorvpn-acme -d "$DOMAIN" --agree-tos --register-unsafely-without-email --non-interactive --keep-until-expiring "${ACME_ARGS[@]}"
fi
python3 - "$APP_DIR/deploy/nginx.conf.template" "$SITE" "$DOMAIN" "$HTTPS_PORT" <<'PYSITE'
import sys
s=open(sys.argv[1]).read().replace('@DOMAIN@',sys.argv[3]).replace('@HTTPS_PORT@',sys.argv[4])
open(sys.argv[2],'w').write(s)
PYSITE
SITE_ADDED=1
ln -s "$SITE" /etc/nginx/sites-enabled/noorvpn.conf
nginx -t; systemctl reload nginx
curl --fail --silent --show-error --max-time 15 --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" "https://$DOMAIN_HOST/healthz" >/dev/null
# Both public routing and TLS must succeed before registering Telegram.
curl --fail --silent --show-error --max-time 15 "https://$DOMAIN_HOST/healthz" >/dev/null
printf 'url = "https://api.telegram.org/bot%s/setWebhook"\ndata-urlencode = "url=https://%s/index.php"\ndata-urlencode = "secret_token=%s"\n' "$TOKEN" "$DOMAIN_HOST" "$WEBHOOK_SECRET" |
 curl --config - --fail --silent --show-error --max-time 20 |
 python3 -c 'import json,sys; x=json.load(sys.stdin); sys.exit(0 if x.get("ok") else 1)'
python3 - "$APP_DIR/deploy/cron.schedule" "$CRON" <<'PYCRON'
import sys
out=['SHELL=/bin/sh','PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']
for line in open(sys.argv[1]):
 if not line.strip() or line.startswith('#'):continue
 schedule,name=line.strip().rsplit(' ',1)
 out.append(f'{schedule} www-data /usr/bin/flock -n /var/www/noorvpn/storage/cron/{name}.lock /usr/bin/php /var/www/noorvpn/cronbot/{name} >>/var/www/noorvpn/storage/cron.log 2>&1')
open(sys.argv[2],'w').write('\n'.join(out)+'\n')
PYCRON
chmod 0644 "$CRON"
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nnginx -t && systemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/noorvpn-nginx
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/noorvpn-nginx
SUCCESS=1
log "Installed NoorVpn: https://$DOMAIN_HOST/panel/"
log 'Use the bot administrator menu to configure panel access. Payment/provider flows require acceptance testing.'

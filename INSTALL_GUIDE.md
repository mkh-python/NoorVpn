# NoorVpn deployment guide

## Install from GitHub

After the public repository is available:

```bash
sudo apt-get update
sudo apt-get install -y git
git clone https://github.com/mkh-python/NoorVpn.git
cd NoorVpn
bash install.sh check --domain bot.example.com
sudo bash install.sh install --domain bot.example.com --admin 123456789 --local-panel
```

Replace the hostname and Telegram user ID. Enter the Telegram token at the private prompt. Install and configure a compatible 3x-ui panel first when using `--local-panel`; this installer does not install a VPN panel, inbound, or client transport. On a clean server, the bot uses TCP 443 if available, otherwise 8443. Confirm the panel subscription URL works from the client network before registering it with the bot.

A GitHub source checkout is not a server backup. Before rebuilding a server, retain any needed private database, panel configuration, certificates and runtime credentials off-host. Never commit them to this public repository. Reinstalling does not restore paid accounts, balances or orders.

The optional subscription reverse proxy is documented in docs/changes/subscription-download-route.md; it is deployment-specific and not installed automatically. Prefer configuring the panel's canonical public subscription URL first.

## Supported installation shape

Ubuntu 24.04/26.04, PHP >=8.2 (distribution PHP), MySQL, Nginx and PHP-FPM. The installer supports new deployments only. Local panel integration targets the modern 3x-ui `/panel/api/clients/*` API; older 2.x inbound APIs are not interchangeable.

Before installation:

1. Point all IPv4 DNS records for the bot hostname directly at this host. Remove AAAA records for this candidate: the generated HTTPS and ACME sites listen on IPv4 only. The current preflight validates IPv4 records and does not validate IPv6. DNS proxy mode is not supported by the preflight.
2. Make TCP 80 reachable for ACME HTTP-01. Make the selected TCP 443 or 8443 reachable for Telegram. The installer does not change firewall rules.
3. Keep a server snapshot and verify existing services. No VPN panel is installed, stopped or reconfigured by the installer.
4. Use a separate Telegram bot token, administrator Telegram ID and actual hostname. Do not use example values.

```bash
bash install.sh check --domain bot.example.com
sudo bash install.sh install --domain bot.example.com --admin 123456789 --local-panel
```

Omit `--local-panel` for a remote VPN panel. Select `--https-port 8443` explicitly when needed. An occupied selected TLS port causes a safe stop.

For automation, create a root-owned mode-600 file containing only the bot token and pass `--token-file /private/bot-token`. Tokens are never accepted as a command-line value.

## Existing local 3x-ui

`--local-panel` discovers settings/inbounds and uses the installed panel's named-token CLI to create a new **NoorVpn** API token. It refuses to rotate a token with that name. The plaintext is persisted only in `/etc/noorvpn-bootstrap/panel.json` (root-only). This private file is outside the source tree and is not packaged.

A manually prepared JSON may be supplied using `--local-panel-config /private/panel.json`:

```json
{
  "url_panel": "https://panel.example.com:2053/private-base",
  "password_panel": "YOUR_PANEL_API_TOKEN",
  "linksubx": "https://sub.example.com/sub",
  "inbounds": [1],
  "resolve": ["panel.example.com:2053:127.0.0.1"]
}
```

Use public subscription URLs, never loopback subscription URLs. Local HTTPS preserves the certificate hostname. `resolve` may contain only explicit loopback mappings. Panel tokens require the permissions needed to create/update/delete clients; this panel version exposes these through the admin scope. Protect and rotate the credential if disclosed.

```bash
sudo php bin/panel.php check --config /private/panel.json
```

The installer registers the panel in the bot database after the schema succeeds. This does not import existing VPN clients as paid bot orders and does not change their quotas.

## Installed paths

- `/var/www/noorvpn`: root-owned code and deployment-specific `config.php` (root:www-data, 640).
- `/var/www/noorvpn/storage`: writable runtime state; HTTP access is denied.
- `/etc/noorvpn/webhook-secret`: private Telegram webhook secret, checked before DB bootstrap.
- `/etc/noorvpn/panel-resolve.json`: optional loopback TLS mappings.
- `/etc/nginx/sites-available/noorvpn*.conf`: additive ACME/TLS sites.
- `/etc/php/<version>/fpm/pool.d/noorvpn.conf`: independent ondemand PHP pool (maximum 3 workers).
- `/etc/cron.d/noorvpn`: CLI jobs with per-job flock locks; existing crontabs are preserved.

An existing valid matching domain certificate is reused without changing its ACME account or renewal routing. When a new certificate is required and multiple Certbot accounts exist, specify `--acme-account ACCOUNT_ID`; that ambiguity is detected before creating the application database. Certificate renewal reloads Nginx after successful renewal. Monitor the Certbot timer, available disk, PHP logs and cron logs.

## Failure and retry

Dependencies may remain installed after a failure. Created bot data/configuration are retained for inspection. Temporary application staging and this install's enabled Nginx links are cleaned up on failure. No destructive automatic rollback drops databases. The installer refuses partial installations on retry; inspect the reported paths and database before deciding how to recover. Do not remove production paths to bypass the check.

No automatic upgrade/uninstall is provided in this candidate. Back up application configuration, database and runtime state before any manual update. Never replace the application `config.php` with the public template.

## Acceptance before production

- Send `/start` using the configured administrator and a separate test user.
- Activate web administration from the Telegram admin menu and sign in over HTTPS.
- Verify blocked users and verification rules cannot purchase/extend services.
- Set explicit products/prices and correct inbound IDs; test purchase, renewal, expired quota and insufficient wallet funds.
- Exercise gateway sandbox callbacks, repeated callbacks and provider failures. Real gateway credentials and balances are needed for a final acceptance test.
- Test Mini App login and data isolation using two separate Telegram accounts.
- Confirm `/config.php`, `/storage/`, `/vendor/`, `/cronbot/` and PHP helper files return 404.
- Verify MTG, SoftEther, 3x-ui and AWG remain healthy.

The bundled checks do not certify all gateways or all inherited code. See TEST_REPORT.md.

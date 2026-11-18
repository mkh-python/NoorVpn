# NoorVpn 0.1.0-rc.3 verification report

This is a release candidate. Passing the checks below does not mean the entire inherited application is defect-free or every provider is compatible.

## Environment actually exercised

- Ubuntu 26.04, PHP 8.5.4, MySQL 8.4.13.
- Existing 3x-ui 3.11.0 with its modern bearer-token client API.
- Locked PhpSpreadsheet 5.11.0 and Endroid QR Code 6.0.9.

## Executed successfully

- Python unit/regression suite: **50 tests passed**; local discovery and privacy, webhook secret/CSRF handling, hashed-password rejection, real loopback HTTP transport, local TLS hostname resolution configuration, panel CLI, 3x-ui request payloads, source packaging and migration-test guard.
- PHP syntax checks: **107 files passed**, plus **12/12** bundled static controls; Bash installer syntax.
- Locked Composer dependency installation as an unprivileged account, plugins/scripts disabled; actual PHP platform validation.
- Composer advisory audit: the original PhpSpreadsheet 5.9.0 had four advisories. It was upgraded to locked 5.11.0; the subsequent audit returned no advisories and no abandoned packages. This is a snapshot, not a guarantee of future advisories.
- Temporary least-privilege MySQL database/user: schema initialization twice, initial records, strict-mode Telegram user registration, local panel registration and duplicate rejection. Temporary database/user removed afterward.
- Actual QR PNG generation and XLSX write/read with the installed libraries.
- Payment status claim accepted once and rejected on repeated claim. This does not exercise external gateway callbacks or full fulfillment.
- Independent temporary Nginx instance: public health/static requests, extensionless API routed to FastCGI rather than served as source, private config/storage/cron/vendor paths denied. The system Nginx was not reloaded for this test.
- Actual local 3x-ui: certificate-verified API authentication, isolated test client creation/read, public subscription delivery, quota/expiry update, disable, attach to chosen inbound, traffic read/reset and deletion. No test clients remained.
- Existing Nginx, MTG, SoftEther, X-UI and AWG services checked active after tests.
- Existing-certificate reuse regression: correct domain/key accepted, mismatched domain and missing key rejected.
- Independent code review identified runtime directory permissions, command-line secret exposure, relative panel-config paths and failed bootstrap reservations. Each was corrected; regression checks were added for relative paths and failed token commands.

## Report-channel and protocol-wizard fixes

Public/private channel and ordinary-group setup now asks for the link before the numeric ID. Forum support is preserved. Unit tests plus actual router simulation and temporary MySQL tests verify permission checks, retry/legacy state recovery, stale topic reset, transactional rollback and cleanup of only newly created forum topics. Source and installed files were verified identical. No live report message was sent by the test tools.

## Not yet verified / release blockers

- Full installation from a clean Ubuntu host, fresh ACME issuance and renewal, and restart/recovery behavior through every failure point. The installer was linted, reviewed and its components exercised; that is not a complete clean-host installation test.
- The administrator has exercised Telegram menus and reported fixes during acceptance. HTTPS web-admin login with a real session and CSRF token passed. End-to-end acceptance of every menu remains incomplete.
- Complete buy/renewal/wallet behavior through Telegram and two-user Mini App access.
- Real or sandbox payment callback/retry/error cases for every gateway.
- VPN integrations other than the tested modern 3x-ui version.
- Ubuntu 24.04: CI configuration is provided, but no GitHub Actions run has occurred yet.
- Generated PHP reseller bots remain denied by the public Nginx site and require separate review/deployment.
- The supplied archive lacked original frontend build sources; prebuilt JavaScript assets were retained.

A live deployment on the existing Ubuntu 26.04 server completed using the existing panel domain on TCP 8443. The original installer stopped because Certbot found multiple old accounts. The installer was fixed to validate/reuse an existing matching certificate and preserve its ACME routing, then resumed successfully without resetting the database or panel. Public health/login/Mini App checks, authenticated empty-update PHP/database bootstrap, CSRF rejection and Telegram getWebhookInfo passed with zero pending updates and no reported Webhook error. A private dedicated local-panel API token is outside the source tree; the public source template contains no deployment credentials. The complete public source was subsequently published to https://github.com/mkh-python/NoorVpn. The published Git tree matched the local release exactly, and an anonymous GitHub ZIP download was verified byte-for-byte against all 207 tracked files.

## November 18 release preparation

Protocol setup retries are covered by an actual-router fixture for missing client, malformed API response, empty inbound list and successful retry. The live purchase adapter was exercised with the current product: isolated creation, subscription delivery and cleanup succeeded without wallet or invoice mutation. A deployment-specific subscription route on the existing HTTPS listener was confirmed by the user to import successfully. That route remains optional; clean deployments must configure and test the panel subscription independently. Release preparation reran 50 unit tests, syntax validation of 107 PHP files, 12 static controls, isolated Nginx checks, temporary MySQL schema/router checks and Composer audit successfully. The source was checked against the deployed PHP files (excluding private config); no code differences were found. Known deployment token/password values and host identifiers were absent from tracked files. GitHub Actions status is separate from the local test results above; no clean-host installation is implied by source publication.

## Fresh Ubuntu 26.04 deployment follow-up

A newly provisioned Ubuntu 26.04 host was configured from the public GitHub source with 3x-ui 3.11.0. First-time ACME exposed a directory-permission defect: umask 077 left intermediate challenge directories at 0700. The installer now explicitly creates the webroot and both challenge directory levels at 0755. A regression reproduces the original failure and verifies traversal under that umask. The deployment resumed from certificate issuance without resetting its database.

Actual verification passed: fresh certificate issuance, HTTPS bot health and private-route denial, empty authenticated webhook bootstrap, zero pending Telegram updates, real web-admin login, dedicated panel API authentication, isolated client create/read/update/attach/traffic/reset/delete, native panel HTTPS subscription delivery on TCP 443, and public WebSocket TLS handshake on the configured VPN port. The bot uses TCP 8443 separately. This validates the new deployment with the documented ACME recovery; it is not a complete test of every fresh-install failure path, payment provider or client application.

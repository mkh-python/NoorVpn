# NoorVpn security and deployment changes

- Existing upstream licensing and source provenance preserved; no hardcoded production credentials included.
- Webhook validates a nonempty secret header before DB/autoloader/update processing. Existing Telegram IP filtering remains as a second check.
- CSRF requires a real server-side session token. Password hashes cannot themselves be used as passwords; legacy plaintext is migrated on successful login.
- SQL value logging removed because values included credentials and personal data.
- Modern 3x-ui attach uses the required JSON object; partial updates include the required email. Client path parameters are URL-encoded.
- Local TLS connections retain the certificate hostname using explicit loopback resolution; certificate validation stays enabled.
- Schema detection is restricted to the current database, migration errors fail loudly, schema updates never register a webhook, and optional registration fields have strict-mode-compatible defaults.
- Locked dependencies installed as an unprivileged builder without plugins/scripts. Known spreadsheet advisories in the supplied lock were addressed.
- Installer refuses existing deployments, schema names and DB users rather than overwriting them. No database root-password changes or destructive repair/uninstall paths.
- Separate Nginx site/FPM pool and CLI cron; runtime writes are confined to storage and explicit image files. HTTP exposure of helper code/runtime state is blocked.
- Credentials are excluded from the source package and common token/private-key patterns are checked before archive creation.

This is targeted hardening of an inherited application, not a comprehensive independent audit. Consult TEST_REPORT.md for gaps before production use.

# NoorVpn public release design

Extend the supplied PHP bot instead of replacing its purchasing and billing logic. Preserve upstream AGPL-3.0 license and attribution. Support existing 3x-ui API integration and provide read-only local panel discovery; never install or restart a VPN panel. Keep public subscription URLs separate from private management URLs.

Use Nginx, PHP-FPM and MySQL with isolated bot database and filesystem. Harden the installer against partial failures, existing deployments, database-user collisions, privileged dependency execution, exposed runtime scripts and expired TLS. Verify Telegram before durable deployment changes. Credentials must never be committed or printed. Domain and Telegram credentials remain deployment inputs.

Automated tests must cover discovery without secrets, installer preflight, webhook authentication, HTTP transport and schema initialization. Run actual locked dependencies and a temporary MySQL schema where feasible. Preserve existing services and client records. Public release must state unverified payment/provider integrations and must not claim zero defects.

Approaches: retain PHP code (chosen for compatibility); rewrite (rejected: loses existing functionality). Local management uses TLS host resolution when available, not disabling certificate verification.

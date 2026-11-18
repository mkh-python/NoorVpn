# Protocol setup retry (2026-11-18)

The 3x-ui wizard now asks explicitly for an existing client's Email. A failed lookup keeps the pending state and retry keyboard consistent, sends one actionable message, and hides raw panel output. Invalid JSON and empty inbound lists cannot overwrite the saved selection. Successful retries save inbound IDs before sending confirmation.

The regression fixture executes the actual router branch with mocked responses for missing client, malformed JSON, empty inbounds and successful retry. Python test discovery includes it.

Separate operational finding: production sampled 79–84% CPU I/O wait, high I/O pressure, and 8.2 GB free disk space. Historical database handshake and Telegram transport timeouts also occurred. This patch addresses the wizard state mismatch; it does not resolve underlying server storage latency or every delayed response. No VPN client or server-wide database configuration was changed.

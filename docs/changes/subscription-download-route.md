# Optional local subscription proxy

For deployments where the client cannot download from the panel subscription port, the optional deploy/local-subscription-proxy.conf.template exposes /subscription/ through the existing bot HTTPS listener. Replace the TLS hostname, local subscription port and subscription path placeholders. Include the location inside the existing server block, validate nginx -t and reload. TLS verification remains enabled, with verification depth 5 for cross-signed certificate chains. Only GET/HEAD requests are allowed; subscription paths are excluded from access logs. The original /sub/ bot invoice handler stays separate.

Before changing marzban_panel.linksubx, verify that a real subscription returns HTTP 200 and the same decoded links through the new path. Then set that panel row to https://BOT_HOST:BOT_HTTPS_PORT/subscription. Existing messages keep the previous URL and must be replaced in the client. This is a connectivity alternative, not proof of a particular mobile-network fault.

Live checks on 2026-11-18: V2Box/v2rayNG request headers returned identical Base64 VLESS subscriptions through both paths, HTTPS verified, and POST was rejected with 403.

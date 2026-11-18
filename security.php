<?php
/** NoorVpn webhook boundary; deliberately independent of the database. */
function noorVpnValidWebhook(string $expected, ?string $provided): bool
{
    return $expected !== '' && $provided !== null && hash_equals($expected, $provided);
}

function noorVpnWebhookSecret(): string
{
    $path = getenv('NOORVPN_WEBHOOK_SECRET_FILE') ?: '/etc/noorvpn/webhook-secret';
    if (!is_readable($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function noorVpnRequireWebhook(): void
{
    $provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !noorVpnValidWebhook(noorVpnWebhookSecret(), $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function noorVpnValidCsrf(string $expected, mixed $provided): bool
{
    return is_string($provided) && $expected !== '' && hash_equals($expected, $provided);
}

function noorVpnVerifyPassword(string $provided, string $stored): bool
{
    if ($provided === '' || $stored === '') { return false; }
    if (password_get_info($stored)['algo'] !== null) {
        return password_verify($provided, $stored);
    }
    // Compatibility for old installations only. Upgrade immediately after login.
    return hash_equals($stored, $provided);
}

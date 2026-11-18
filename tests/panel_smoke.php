<?php
/** Explicit live integration test. Creates one short-lived test client, deletes it. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--private-config' || empty($argv[2]) || !in_array('--allow-client-mutation', $argv, true)) {
    fwrite(STDERR, "Usage: php tests/panel_smoke.php --private-config /private/panel.json --allow-client-mutation\n"); exit(1);
}
require_once __DIR__ . '/../x-ui_single.php';
$private = $argv[2];
if (!is_file($private) || (fileperms($private) & 0077)) { fwrite(STDERR, "Private config mode 600 required\n"); exit(1); }
$panel = json_decode(file_get_contents($private), true, 512, JSON_THROW_ON_ERROR);
$panel['conecton'] = 'offconecton'; $panel['on_hold_test'] = '0';
$resolve = tempnam(sys_get_temp_dir(), 'noorvpn-resolve-');
file_put_contents($resolve, json_encode($panel['resolve'] ?? []));
putenv('NOORVPN_PANEL_RESOLVE_FILE=' . $resolve);
$username = 'noorvpn_test_' . bin2hex(random_bytes(6));
$created = false; $passed = false;
function expectPanel(array $response, string $operation): array {
    $body = json_decode($response['body'] ?? '', true);
    if (($response['status'] ?? 0) !== 200 || !is_array($body) || empty($body['success'])) {
        throw new RuntimeException("$operation failed (HTTP " . ($response['status'] ?? 'network-error') . '): ' . (string)($body['msg'] ?? 'no API message'));
    }
    echo "$operation: OK\n";
    return $body;
}
try {
    expectPanel(status_server_xui($panel), 'Authenticated local status');
    $created = true; // Cleanup also covers a timeout after the panel accepted creation.
    expectPanel(addClient($panel, $username, time()+300, bin2hex(random_bytes(8)), 1048576, json_encode([$panel['inbounds'][0]]), 'NoorVpn-test'), 'Create isolated test client');
    $body = expectPanel(get_clinets($username, $panel), 'Read created client');
    $client = $body['obj']['client'] ?? [];
    if ((int)($client['totalGB'] ?? -1) !== 1048576) { throw new RuntimeException('Quota mismatch'); }
    $subId = $client['subId'] ?? '';
    if ($subId === '') { throw new RuntimeException('Subscription ID missing'); }
    $subscription = (new CurlRequest(rtrim($panel['linksubx'], '/') . '/' . rawurlencode($subId)))->get();
    $links = $subscription['body'] ?? '';
    if (!preg_match('~(?:vless|vmess|trojan|ss)://~', $links)) { $links = base64_decode($links, true) ?: ''; }
    if (($subscription['status'] ?? 0) !== 200 || !preg_match('~(?:vless|vmess|trojan|ss)://~', $links)) { throw new RuntimeException('Public subscription delivery failed'); }
    echo "Public subscription delivery: OK\n";
    expectPanel(updateClient($panel, $username, ['enable'=>false,'totalGB'=>2097152,'expiryTime'=>(time()+600)*1000]), 'Update quota/expiry and disable');
    $body = expectPanel(get_clinets($username, $panel), 'Verify updated client');
    if (!empty($body['obj']['client']['enable']) || (int)($body['obj']['client']['totalGB'] ?? -1) !== 2097152) { throw new RuntimeException('Updated state mismatch'); }
    expectPanel(attach_service($panel, $username, [$panel['inbounds'][0]]), 'Attach configured inbound');
    expectPanel(used_data_3xui($panel, $username), 'Read client traffic');
    expectPanel(ResetUserDataUsagex_uisin($username, $panel), 'Reset test traffic');
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, 'NoorVpn test: ' . $error->getMessage() . "\n");
} finally {
    if ($created) {
        try { expectPanel(removeClient($panel, $username), 'Delete isolated test client'); }
        catch (Throwable $error) { fwrite(STDERR, "Cleanup failed; remove client $username manually\n"); $passed = false; }
    }
    @unlink($resolve);
}
exit($passed ? 0 : 1);

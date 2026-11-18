<?php
/** Private-file panel setup. No tokens are accepted through process arguments. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$initialDirectory = getcwd();
chdir(dirname(__DIR__));
require_once __DIR__ . '/../request.php';
function panelFail(string $message): never { fwrite(STDERR, "NoorVpn: $message\n"); exit(1); }
$action = $argv[1] ?? '';
if (!in_array($action, ['check','register'], true) || ($argv[2] ?? '') !== '--config' || empty($argv[3])) {
    panelFail('Usage: php bin/panel.php check|register --config /private/panel.json');
}
$path = $argv[3];
if (!str_starts_with($path, '/')) { $path = $initialDirectory . '/' . $path; }
if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
    panelFail('Panel configuration must be a private regular file (mode 600)');
}
$resolveCreated = false;
try {
    $panel = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach (['url_panel','password_panel','linksubx','inbounds'] as $field) {
        if (empty($panel[$field])) { panelFail("Missing panel field: $field"); }
    }
    $url = parse_url($panel['url_panel']);
    if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) { panelFail('Invalid panel URL'); }
    if ($url['scheme'] === 'http' && !in_array($url['host'], ['127.0.0.1','localhost','::1'], true)) { panelFail('Remote panel connections require HTTPS'); }
    if (!is_array($panel['inbounds']) || array_filter($panel['inbounds'], fn($id) => !is_int($id) || $id <= 0)) { panelFail('Inbound IDs must be positive integers'); }
    $request = new CurlRequest(rtrim($panel['url_panel'],'/') . '/panel/api/server/status');
    $request->setBearerToken($panel['password_panel']);
    $request->setResolve($panel['resolve'] ?? []);
    $response = $request->get();
    $body = json_decode($response['body'] ?? '', true);
    if (($response['status'] ?? 0) !== 200 || !is_array($body) || empty($body['success'])) { panelFail('Panel API check failed; verify API token, base path, TLS and permissions'); }
    echo "NoorVpn: authenticated panel connection OK\n";
    if ($action === 'check') { exit(0); }
    require_once __DIR__ . '/../config.php';
    $name = 'NoorVpn-local';
    $statement = $pdo->prepare('SELECT COUNT(*) FROM marzban_panel WHERE name_panel=? OR url_panel=?');
    $statement->execute([$name, $panel['url_panel']]);
    if ((int) $statement->fetchColumn()) { panelFail('This panel is already registered; refusing to overwrite'); }
    $language = require __DIR__ . '/../lang/fa.php';
    $prices = json_encode(['f'=>'4000','n'=>'4000','n2'=>'4000']);
    $values = [
        'code_panel'=>'noor-' . bin2hex(random_bytes(6)), 'name_panel'=>$name,
        'status'=>'active', 'url_panel'=>rtrim($panel['url_panel'],'/'), 'username_panel'=>'api-token',
        'password_panel'=>$panel['password_panel'], 'agent'=>'all', 'sublink'=>'onsublink', 'config'=>'offconfig',
        'MethodUsername'=>$language['keyboard']['numericIdRandom'], 'TestAccount'=>'ONTestAccount', 'limit_panel'=>'unlimted',
        'namecustom'=>'noor', 'Methodextend'=>$language['keyboard']['resetVolumeTime'], 'conecton'=>'offconecton',
        'linksubx'=>rtrim($panel['linksubx'],'/'), 'inboundid'=>(string)$panel['inbounds'][0], 'type'=>'x-ui_single',
        'inbounds'=>json_encode($panel['inbounds']), 'on_hold_test'=>'0', 'version_panel'=>'3',
        'time_usertest'=>'1', 'val_usertest'=>'100', 'inboundstatus'=>'offinbounddisable',
        'inbound_deactive'=>'0', 'secret_code'=>bin2hex(random_bytes(8)), 'status_extend'=>'on_extend',
        'priceextravolume'=>$prices, 'pricecustomvolume'=>$prices, 'pricecustomtime'=>$prices, 'priceextratime'=>$prices,
        'mainvolume'=>json_encode(['f'=>'1','n'=>'1','n2'=>'1']), 'maxvolume'=>json_encode(['f'=>'1000','n'=>'1000','n2'=>'1000']),
        'maintime'=>json_encode(['f'=>'1','n'=>'1','n2'=>'1']), 'maxtime'=>json_encode(['f'=>'365','n'=>'365','n2'=>'365']),
    ];
    $resolvePath = getenv('NOORVPN_PANEL_RESOLVE_FILE') ?: '/etc/noorvpn/panel-resolve.json';
    if (!str_starts_with($resolvePath, '/')) { panelFail('Resolve configuration path must be absolute'); }
    if (file_exists($resolvePath)) { panelFail('Panel resolve configuration already exists; review before changing it'); }
    // Save TLS resolution before DB insert. The file contains host mappings only.
    $resolve = json_encode($panel['resolve'] ?? [], JSON_THROW_ON_ERROR);
    if (file_put_contents($resolvePath, $resolve, LOCK_EX) === false) { panelFail('Unable to save local panel resolution'); }
    chmod($resolvePath, 0644);
    $resolveCreated = true;
    $columns = '`' . implode('`,`', array_keys($values)) . '`';
    $statement = $pdo->prepare('INSERT INTO marzban_panel (' . $columns . ') VALUES (' . implode(',',array_fill(0,count($values),'?')) . ')');
    $statement->execute(array_values($values));
    echo "NoorVpn: local panel registered; no existing VPN clients were changed\n";
} catch (Throwable $error) {
    if ($resolveCreated) { @unlink($resolvePath); }
    // Error messages from transports/database drivers can include secret URLs.
    panelFail('Operation failed. Check configuration, database access and panel API compatibility.');
}

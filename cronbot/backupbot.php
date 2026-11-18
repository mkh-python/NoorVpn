<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Must run from CLI/cron; never serve database backups from the web root.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';
$textbotlang = languagechange();
$setting = select('setting', '*');
$report = select('topicid', 'idreport', 'report', 'backupfile', 'select');
$reportbackup = $report['idreport'] ?? null;
$chat = $setting['Channel_Report'] ?? '';
if (!$chat) { error_log('Backup: report channel missing'); exit(1); }
$tmp = sys_get_temp_dir() . '/vpnbot-backup-' . bin2hex(random_bytes(12));
if (!mkdir($tmp, 0700)) { throw new RuntimeException('Backup temp directory unavailable'); }
try {
    $botlist = select('botsaz', '*', null, null, 'fetchAll');
    if (is_array($botlist)) {
        foreach ($botlist as $bot) {
            $folderName = (string) ($bot['id_user'] ?? '') . (string) ($bot['username'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/D', $folderName)) { continue; }
            $base = realpath(__DIR__ . '/../vpnbot/' . $folderName);
            $root = realpath(__DIR__ . '/../vpnbot');
            if (!$base || !$root || !str_starts_with($base, $root . DIRECTORY_SEPARATOR)) { continue; }
            $zip = new ZipArchive();
            $archive = $tmp . '/bot-' . bin2hex(random_bytes(8)) . '.zip';
            if ($zip->open($archive, ZipArchive::CREATE) !== true) { continue; }
            foreach (['product.json', 'product_name.json'] as $filename) {
                if (is_file($base . '/' . $filename)) { $zip->addFile($base . '/' . $filename, $filename); }
            }
            $dataDir = $base . '/data';
            if (is_dir($dataDir)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $item) {
                    if ($item->isFile() && !$item->isLink()) {
                        $zip->addFile($item->getPathname(), 'data/' . substr($item->getPathname(), strlen($dataDir) + 1));
                    }
                }
            }
            $zip->close();
            telegram('sendDocument', ['chat_id' => $chat, 'message_thread_id' => $reportbackup, 'document' => new CURLFile($archive), 'caption' => '@' . ($bot['username'] ?? '')]);
            @unlink($archive);
        }
    }
    // A private defaults-file prevents the password appearing in process args.
    $defaults = $tmp . '/my.cnf';
    $ini = "[client]\n" . 'host=' . str_replace(["\r", "\n"], '', (string) $dbhost) . "\n" . 'user=' . str_replace(["\r", "\n"], '', (string) $usernamedb) . "\n" . 'password=' . '"' . addcslashes((string) $passworddb, chr(92) . chr(34)) . '"' . "\n";
    file_put_contents($defaults, $ini, LOCK_EX);
    chmod($defaults, 0600);
    $output = $tmp . '/database.sql';
    $command = 'mysqldump --defaults-extra-file=' . escapeshellarg($defaults) . ' --no-tablespaces --single-transaction --result-file=' . escapeshellarg($output) . ' ' . escapeshellarg($dbname);
    $status = 1;
    exec($command . ' 2>/dev/null', $ignored, $status);
    if ($status !== 0 || !is_file($output) || filesize($output) === 0) {
        telegram('sendmessage', ['chat_id'=>$chat,'message_thread_id'=>$reportbackup,'text'=>$textbotlang['keyboard']['backupError'] ?? 'Backup failed']);
        error_log('Backup: mysqldump failed');
    } else {
        telegram('sendDocument', ['chat_id'=>$chat,'message_thread_id'=>$reportbackup,'document'=>new CURLFile($output),'caption'=>$textbotlang['Admin']['report']['backupCaption'] ?? 'Database backup']);
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
    @rmdir($tmp);
}

<?php
/** CLI-only diagnostic; outputs no tokens/passwords. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
$required = ['curl','pdo_mysql','mbstring','dom','xml','zip','gd','intl','bcmath'];
foreach ($required as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1);
    }
}
require_once __DIR__ . '/../config.php';
foreach (['user','setting','admin','marzban_panel','product','invoice','Payment_report','PaySetting','topicid','user_verification'] as $table) {
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    if (!(int) $query->fetchColumn()) { fwrite(STDERR, "Missing table: $table\n"); exit(1); }
}
foreach (['setting','admin'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() < 1) {
        fwrite(STDERR, "Missing initial records: $table\n"); exit(1);
    }
}
echo "NoorVpn: database schema and initial records OK\n";

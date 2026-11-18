<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'report_setup.php';
noorVpnMigrateReportSetup($pdo);
noorVpnMigrateReportSetup($pdo);
$pdo->exec("UPDATE setting SET Channel_Report='-1001111111111',report_channel_link='https://t.me/previous_channel'");
try { noorVpnSaveReportSetup($pdo, ['ok'=>false]); throw new RuntimeException('Unverified result accepted'); }
catch (InvalidArgumentException $expected) {}
$topics = array_fill_keys($pdo->query('SELECT report FROM topicid')->fetchAll(PDO::FETCH_COLUMN), 0);
$result = ['ok'=>true,'chat_id'=>'-1001234567890','link'=>'https://t.me/+private_fixture','topics'=>$topics];
noorVpnSaveReportSetup($pdo, $result);
if ($pdo->query('SELECT Channel_Report FROM setting')->fetchColumn() !== $result['chat_id']) { throw new RuntimeException('Chat not saved'); }
if ($pdo->query('SELECT report_channel_link FROM setting')->fetchColumn() !== $result['link']) { throw new RuntimeException('Link not saved'); }
if ((int)$pdo->query('SELECT COUNT(*) FROM topicid WHERE idreport<>0')->fetchColumn()) { throw new RuntimeException('Stale topic IDs were not cleared'); }
$pdo->exec("ALTER TABLE setting ADD CONSTRAINT noorvpn_report_failure CHECK (Channel_Report <> '-1009999999999')");
try {
    $invalid = $result; $invalid['chat_id'] = '-1009999999999'; $invalid['topics'] = array_fill_keys(array_keys($topics), 88);
    try { noorVpnSaveReportSetup($pdo, $invalid); throw new RuntimeException('Fixture failure did not occur'); }
    catch (PDOException $expected) {}
    if ($pdo->query('SELECT Channel_Report FROM setting')->fetchColumn() !== $result['chat_id']) { throw new RuntimeException('Destination changed after failed transaction'); }
    if ((int)$pdo->query('SELECT COUNT(*) FROM topicid WHERE idreport<>0')->fetchColumn()) { throw new RuntimeException('Topic update did not roll back'); }
} finally { $pdo->exec('ALTER TABLE setting DROP CHECK noorvpn_report_failure'); }
echo "Report schema, channel save, stale-topic reset and transactional rollback: OK\n";

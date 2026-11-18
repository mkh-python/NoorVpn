<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once __DIR__ . '/../function.php';
$qr = createqrcode('https://example.invalid/noorvpn-test');
if (!$qr || !str_starts_with($qr->getString(), "\x89PNG\r\n\x1a\n")) { throw new RuntimeException('QR generation failed'); }
echo "QR generation: OK\n";
$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getActiveSheet()->setCellValue('A1', 'NoorVpn');
$file = tempnam(sys_get_temp_dir(), 'noorvpn-xlsx-');
try {
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($file);
    $loaded = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    if ($loaded->getActiveSheet()->getCell('A1')->getValue() !== 'NoorVpn') { throw new RuntimeException('Spreadsheet round trip failed'); }
    $loaded->disconnectWorksheets();
    echo "Spreadsheet XLSX round trip: OK\n";
} finally { @unlink($file); $spreadsheet->disconnectWorksheets(); }
$statement = $pdo->prepare('INSERT INTO Payment_report (id_user,id_order,payment_Status) VALUES (?,?,?)');
$statement->execute(['987654321','noorvpn-claim-fixture','pending']);
if (!claimPaymentPaid('noorvpn-claim-fixture') || claimPaymentPaid('noorvpn-claim-fixture')) { throw new RuntimeException('Duplicate payment claim guard failed'); }
echo "Duplicate payment claim guard: OK\n";

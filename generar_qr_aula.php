<?php
// generar_qr_aula.php
// Genera un QR PNG para el aula seleccionada
require_once __DIR__ . '/vendor/autoload.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$aula = isset($_GET['aula']) ? trim($_GET['aula']) : '';
if (!$aula) {
    echo '<div style="color:#b71c1c;font-weight:bold;">Aula no especificada.</div>';
    exit;
}

// Detectar IP local real para la URL del QR
$host = $_SERVER['HTTP_HOST'];
$ip = '';
if ($host === 'localhost' || $host === '127.0.0.1' || $host === '' || $host === ':1') {
    // Buscar IP local real
    $ip = gethostbyname(gethostname());
    if (!filter_var($ip, FILTER_VALIDATE_IP) || $ip === '127.0.0.1') {
        // Buscar en interfaces de red
        $ip = '';
        if (function_exists('shell_exec')) {
            $out = shell_exec('ipconfig');
            if ($out && preg_match('/Direcci[oó]n IPv[4]?\s*:\s*([0-9.]+)/i', $out, $m)) {
                $ip = $m[1];
            }
        }
    }
    if (!$ip) $ip = 'localhost';
    $host = $ip;
}
$url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
    '://' . $host . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) .
    '/aula_info.php?aula=' . urlencode($aula);

$qr = QrCode::create($url)
    ->setSize(320)
    ->setMargin(16);
$writer = new PngWriter();
$result = $writer->write($qr);
$qrDataUri = $result->getDataUri();
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>QR Aula <?= htmlspecialchars($aula) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background: #fafafa; text-align: center; margin: 0; padding: 0; }
        .contenedor { max-width: 420px; margin: 32px auto; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px #0002; padding: 28px 18px 18px 18px; }
        h2 { color: #b71c1c; margin-bottom: 18px; font-size: 2em; }
        .qr-img { margin: 0 auto 18px auto; display: block; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px #0001; padding: 8px; }
        .url-pie { font-size: 0.98em; color: #444; background: #f8f8f8; border-radius: 6px; padding: 8px 6px; margin-top: 12px; word-break: break-all; }
        @media (max-width: 600px) { .contenedor { padding: 8px 2px; } h2 { font-size: 1.2em; } }
    </style>
</head>
<body>
    <div class="contenedor">
        <h2><?= htmlspecialchars($aula) ?></h2>
        <img src="<?= $qrDataUri ?>" alt="QR Aula <?= htmlspecialchars($aula) ?>" class="qr-img" width="320" height="320">
        <div class="url-pie">URL: <br><span><?= htmlspecialchars($url) ?></span></div>
    </div>
</body>
</html>

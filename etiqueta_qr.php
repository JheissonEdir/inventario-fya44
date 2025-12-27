<?php
// etiqueta_qr.php
// Genera una etiqueta individual con QR y datos del bien
require_once __DIR__ . '/vendor/autoload.php'; // Para QR
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$bien = null;
if ($id > 0) {
    $res = $conn->query("SELECT * FROM inventario WHERE id=$id LIMIT 1");
    if ($res && $res->num_rows) {
        $bien = $res->fetch_assoc();
    }
}
if (!$bien) {
    echo '<div style="color:#b71c1c;font-weight:bold;font-size:1.3em;">Bien no encontrado</div>';
    exit;
}
// Generar QR (con info básica)
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
$qrData = "Bien: {$bien['denominacion']}\nAula: {$bien['aula_funcional']}\nSerie: {$bien['serie']}\nID: {$bien['id']}";
$qr = new QrCode($qrData);
$qr->setSize(220);
$writer = new PngWriter();
$qrResult = $writer->write($qr);
$qrBase64 = 'data:image/png;base64,' . base64_encode($qrResult->getString());
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Etiqueta QR - <?= htmlspecialchars($bien['denominacion']) ?></title>
    <style>
        body { background: #fff; margin: 0; padding: 0; }
        .etiqueta {
            width: 380px;
            height: 220px;
            border: 2.5px solid #b71c1c;
            border-radius: 18px;
            box-shadow: 0 4px 18px #0002;
            margin: 24px auto;
            padding: 18px 18px 10px 18px;
            display: flex;
            flex-direction: row;
            align-items: center;
            background: #fff;
        }
        .qr {
            flex: 0 0 120px;
            text-align: center;
        }
        .qr img { width: 110px; height: 110px; }
        .info {
            flex: 1 1 220px;
            padding-left: 18px;
            color: #222;
        }
        .info h2 {
            font-size: 1.25em;
            color: #b71c1c;
            margin: 0 0 8px 0;
            font-weight: bold;
        }
        .info .dato {
            font-size: 1.08em;
            margin-bottom: 6px;
        }
        .info .aula {
            font-size: 1.13em;
            color: #1976d2;
            font-weight: bold;
        }
        .info .serie {
            font-size: 1em;
            color: #444;
        }
        .logo {
            position: absolute;
            top: 18px;
            right: 18px;
            height: 38px;
        }
        @media print {
            body { background: #fff; }
            .etiqueta { box-shadow: none; margin: 0; }
            .logo { display: none; }
        }
    </style>
</head>
<body>
    <div class="etiqueta">
        <div class="qr">
            <img src="<?= $qrBase64 ?>" alt="QR Bien">
        </div>
        <div class="info">
            <h2><?= htmlspecialchars($bien['denominacion']) ?></h2>
            <div class="aula">Aula: <?= htmlspecialchars($bien['aula_funcional']) ?></div>
            <div class="serie">Serie: <?= htmlspecialchars($bien['serie']) ?></div>
            <div class="dato">ID: <?= $bien['id'] ?></div>
        </div>
        <img src="logo_fya44.png" class="logo" alt="Logo">
    </div>
    <div style="text-align:center;margin-top:18px;">
        <button onclick="window.print()" style="background:#b71c1c;color:#fff;font-weight:bold;padding:8px 22px;border:none;border-radius:8px;font-size:1.1em;box-shadow:0 2px 8px #0001;">Imprimir etiqueta</button>
    </div>
</body>
</html>

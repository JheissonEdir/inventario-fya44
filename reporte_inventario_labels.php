<?php
// reporte_inventario_labels.php
// Genera etiquetas/hojas por cada bien (una por página) para impresión/PDF
// Requiere $datos (array) y opcionalmente $export_cols

// Preparar logos y QR como en la plantilla de tablas
$logo_path = __DIR__ . '/logo_fya44.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}

// Generar QRs en base64
// Nota: La generación de QR codes es intensiva en CPU. Limitar a ~100 registros.
set_time_limit(180); // 3 minutos para generación de QR codes
$qr_imgs = [];
if (!function_exists('make_qr_base64')) {
    function make_qr_base64($text) {
        $qr = new Endroid\QrCode\QrCode($text);
        $qr->setSize(120);
        $qr->setMargin(8);
        $writer = new Endroid\QrCode\Writer\PngWriter();
        $qrResult = $writer->write($qr);
        return 'data:image/png;base64,' . base64_encode($qrResult->getString());
    }
}
$total = count($datos);
foreach ($datos as $idx => $fila) {
    $qrData = "Bien: " . ($fila['denominacion'] ?? '') . "\nAula: " . ($fila['aula_funcional'] ?? '') . "\nSerie: " . ($fila['serie'] ?? '') . "\nID: " . ($fila['id'] ?? '');
    $qr_imgs[$fila['id']] = make_qr_base64($qrData);
    // Feedback cada 10 registros para evitar timeout de PHP
    if (($idx + 1) % 10 === 0) {
        @ob_flush();
        @flush();
    }
}

// Estilos para etiquetas: cada etiqueta en su propia página (page-break-after)
?>
<style>
@page { size: A7 portrait; margin: 4mm; }
.body-font { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; }
body{font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; margin:0; padding:0}
.label-page{width:100%;height:100%;display:block; page-break-after:always; page-break-inside:avoid}
.label-card{border:1px solid #ccc;padding:6px;border-radius:6px;display:flex;gap:8px;align-items:flex-start;max-width:100%;box-sizing:border-box}
.label-left{flex:1}
.label-right{width:110px;text-align:center}
.label-title{font-size:12px;color:#b71c1c;margin-bottom:2px}
.small{font-size:10px;color:#333}
.kv{font-weight:bold;color:#1976d2}
.field{margin:3px 0}
.small .kv{font-weight:600}
</style>

<?php foreach ($datos as $fila): ?>
<div class="label-page">
    <div class="label-card">
        <div class="label-left">
            <?php if ($logo_base64): ?>
                <img src="<?= $logo_base64 ?>" alt="logo" style="height:38px;margin-bottom:4px;background:#fff;padding:3px;border-radius:4px;">
            <?php endif; ?>
            <div class="label-title"><?= htmlspecialchars($fila['denominacion'] ?? '') ?></div>
            <div class="small field"><span class="kv">Aula:</span> <?= htmlspecialchars($fila['aula_funcional'] ?? '') ?></div>
            <div class="small field"><span class="kv">Serie:</span> <?= htmlspecialchars($fila['serie'] ?? '') ?></div>
            <div class="small field"><span class="kv">Marca/Modelo:</span> <?= htmlspecialchars(trim(($fila['marca'] ?? '') . ' ' . ($fila['modelo'] ?? ''))) ?></div>
            <div class="small field"><span class="kv">Estado:</span> <?= htmlspecialchars($fila['estado'] ?? '') ?></div>
            <div class="small field"><span class="kv">Usuario:</span> <?= htmlspecialchars($fila['usuario_responsable'] ?? '') ?></div>
            <div class="small field"><span class="kv">Ubicación:</span> <?= htmlspecialchars($fila['ubicacion'] ?? '') ?></div>
            <div class="small field"><span class="kv">ID registro:</span> <?= htmlspecialchars($fila['id'] ?? '') ?></div>
            <div class="small field" style="margin-top:6px;color:#666;font-size:9px">Generado: <?= date('Y-m-d H:i') ?></div>
        </div>
        <div class="label-right">
            <img src="<?= $qr_imgs[$fila['id']] ?>" alt="QR" style="width:90px;height:90px;display:block;margin-bottom:6px">
            <div style="font-size:10px;color:#333">Código: <br><span style="font-family:monospace;font-weight:bold;font-size:12px"><?= htmlspecialchars($fila['serie'] ?? '') ?></span></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
// Nota: Dompdf interpretará page-break-after para separar páginas
?>

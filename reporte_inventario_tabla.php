<?php
// reporte_inventario_tabla.php
// Fragmento HTML para Dompdf (tabla de reporte)
?>
<style>
@page { margin: 8mm; }
body{ font-family: "DejaVu Sans", Arial, sans-serif; color:#222; }
/* Forzar layout fijo para que los anchos porcentuales se respeten y el texto haga wrap */
table { border-collapse: collapse; width: 100%; page-break-inside:auto; table-layout: fixed; font-size: 10px; word-wrap: break-word; }
th, td { border: 1px solid #dcdcdc; padding: 8px 6px; vertical-align: top; word-break: normal; white-space: normal; overflow-wrap: break-word; }
th { background: #f2dede; color: #b71c1c; font-weight: 700; padding: 10px 8px; text-align:left; font-size:12px; }
tbody tr:nth-child(even) { background: #fafafa; }
tbody tr { page-break-inside: avoid; page-break-after: auto; }
thead { display: table-header-group; }
tfoot { display: table-footer-group; }
.col-denom { min-width: 220px; }
.col-obs { min-width: 180px; }
.col-qr { width: 60px; text-align:center }
.col-qty { width: 60px; text-align:center }
.col-date { width: 90px; }
.col-small { width: 70px; }
.small { font-size: 10px; color:#444 }
.qr-row td { border:none; padding-top:6px; padding-bottom:12px; }
.qr-box { display:flex; justify-content:flex-end; align-items:center; gap:12px }

/* Asegurar que los encabezados no se rompan por carácter */
th { line-height:1.1; }
th.col-denom, td.col-denom { min-width:160px; }
th.col-obs, td.col-obs { min-width:140px; }
th.col-qty, td.col-qty { min-width:50px; text-align:center }
th.col-date, td.col-date { min-width:80px }
th.col-small, td.col-small { min-width:60px }

.col-small td, .col-qty td, .col-date td { }
/* Columnas compactas: reducir fuente y truncar con ellipsis cuando sea necesario */
td.col-small, td.col-qty, td.col-date { font-size:8.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.col-qr td img { width:44px; height:44px }
td.col-denom { font-size:11px }
td.col-obs { font-size:11px }
</style>
</style>
<?php
$logo_path = __DIR__ . '/logo_fya44.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}
?>
<div style="text-align:center;margin-bottom:0;">
    <?php if ($logo_base64): ?>
        <img src="<?= $logo_base64 ?>" alt="Logo Fe y Alegría 44" style="height:80px;vertical-align:middle;margin-bottom:8px;background:#fff;padding:6px;border-radius:8px;">
    <?php else: ?>
        <span style="color:#b71c1c;font-weight:bold;">[Logo no disponible]</span>
    <?php endif; ?>
</div>
<h2 style="color:#b71c1c;text-align:center;margin-bottom:0;">Inventario Fe y Alegría 2025</h2>
<div style="text-align:center;color:#444;margin-bottom:18px;">Reporte de bienes, fungibles y donaciones</div>
<?php
$all_cols = [
    'nivel'=>'Nivel',
    'aula_funcional'=>'Aula',
    'denominacion'=>'Denominación',
    'cantidad'=>'Cantidad',
    'marca'=>'Marca',
    'modelo'=>'Modelo',
    'tipo'=>'Tipo',
    'color'=>'Color',
    'serie'=>'Serie',
    'documento_alta'=>'Doc. Alta',
    'fecha_compra'=>'Fecha Compra',
    'numero_documento'=>'N° Doc',
    'estado'=>'Estado',
    'procedencia'=>'Procedencia',
    'observaciones'=>'Obs.',
    'usuario_responsable'=>'Usuario',
    'ubicacion'=>'Ubicación',
    'fecha_registro'=>'Fecha Registro'
];
// columnas solicitadas por el controlador
$cols = isset($export_cols) ? $export_cols : array_keys($all_cols);
// Si se pidieron muchas columnas para PDF, limitar a un subconjunto legible
if (php_sapi_name() !== 'cli' && isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
    if (count($cols) > 12) {
        $preferred = ['nivel','aula_funcional','denominacion','cantidad','marca','modelo','serie','estado','usuario_responsable','ubicacion','fecha_registro','observaciones'];
        $cols_for_pdf = array_values(array_intersect($preferred, $cols));
        if (count($cols_for_pdf) < 6) {
            $cols_for_pdf = array_slice($cols, 0, 12);
        }
        $cols_note = true;
    } else {
        $cols_for_pdf = $cols;
        $cols_note = false;
    }
} else {
    $cols_for_pdf = $cols;
    $cols_note = false;
}
// Resumen estadístico para PDF
$resumen = [
    'total' => count($datos),
    'por_tipo' => [],
    'por_nivel' => [],
    'por_procedencia' => []
];
foreach ($datos as $fila) {
    $resumen['por_tipo'][$fila['tipo']] = ($resumen['por_tipo'][$fila['tipo']] ?? 0) + 1;
    $resumen['por_nivel'][$fila['nivel']] = ($resumen['por_nivel'][$fila['nivel']] ?? 0) + 1;
    $resumen['por_procedencia'][$fila['procedencia']] = ($resumen['por_procedencia'][$fila['procedencia']] ?? 0) + 1;
}
?>
<div style="margin:18px 0 8px 0; padding:10px 12px; background:#f8f8f8; border-radius:8px; max-width:900px; margin-left:auto; margin-right:auto; font-size:0.98em;">
    <b>Total de bienes:</b> <?= $resumen['total'] ?> &nbsp;|
    <b>Por tipo:</b>
    <?php foreach($resumen['por_tipo'] as $k=>$v) echo "$k: $v &nbsp;"; ?>|
    <b>Por nivel:</b>
    <?php foreach($resumen['por_nivel'] as $k=>$v) echo "$k: $v &nbsp;"; ?>|
    <b>Por procedencia:</b>
    <?php foreach($resumen['por_procedencia'] as $k=>$v) echo "$k: $v &nbsp;"; ?>
</div>
<?php
$qr_dir = __DIR__ . '/tmp_qr';
if (!is_dir($qr_dir)) mkdir($qr_dir, 0777, true);
$qr_imgs = [];
foreach ($datos as $fila) {
    $qrData = "Bien: ".$fila['denominacion']."\nAula: ".$fila['aula_funcional']."\nSerie: ".$fila['serie']."\nID: ".$fila['id'];
    $qr = new Endroid\QrCode\QrCode($qrData);
    $qr->setSize(48);
    $writer = new Endroid\QrCode\Writer\PngWriter();
    $qrResult = $writer->write($qr);
    $qr_imgs[$fila['id']] = 'data:image/png;base64,' . base64_encode($qrResult->getString());
}
?>
<?php if ($cols_note): ?>
    <div style="max-width:1000px;margin:8px auto 12px auto;padding:8px;background:#fff5f5;border:1px solid #ffdede;color:#b71c1c;border-radius:6px;font-size:12px;">Mostrando columnas principales para PDF. Para exportar todos los campos use <b>Exportar Excel</b>.</div>
<?php endif; ?>
<table>
    <?php
    // Construir colgroup con anchos porcentuales para mejor distribución
    $totalCols = count($cols_for_pdf) + 1; // +1 por QR
    $colPercents = [];
    foreach ($cols_for_pdf as $col) {
        // Asignación recomendada: más espacio para denominación/observaciones (Opción A)
        if ($col === 'denominacion') $colPercents[$col] = 40;
        elseif ($col === 'observaciones') $colPercents[$col] = 25;
        elseif (in_array($col, ['marca','modelo'])) $colPercents[$col] = 7;
        elseif (in_array($col, ['usuario_responsable','ubicacion'])) $colPercents[$col] = 6;
        elseif (in_array($col, ['fecha_compra','fecha_registro'])) $colPercents[$col] = 6;
        elseif ($col === 'cantidad') $colPercents[$col] = 4;
        else $colPercents[$col] = 4;
    }
    // Ajustar para que la suma total sea exactamente 100 y reservar 6% para QR
    $qrPercent = 6;
    $sumCols = array_sum($colPercents);
    if ($sumCols + $qrPercent != 100) {
        $available = 100 - $qrPercent;
        // normalizar proporcionalmente y redondear
        $norm = [];
        $i = 0;
        $roundedSum = 0;
        foreach ($colPercents as $k => $v) {
            $r = max(3, floor($v * $available / $sumCols));
            $norm[$k] = $r;
            $roundedSum += $r;
            $i++;
        }
        // Ajustar la diferencia restante incrementando la columna 'denominacion' si existe, sino la primera
        $diff = $available - $roundedSum;
        if ($diff > 0) {
            if (isset($norm['denominacion'])) $norm['denominacion'] += $diff;
            else { $firstKey = array_key_first($norm); $norm[$firstKey] += $diff; }
        } elseif ($diff < 0) {
            // reducir desde las columnas pequeñas si hay exceso
            foreach ($norm as $k => &$v) {
                if ($diff == 0) break;
                $take = min($v - 3, abs($diff));
                if ($take > 0) { $v -= $take; $diff += $take; }
            }
            unset($v);
        }
        $colPercents = $norm;
    }
    ?>
    <colgroup>
        <?php foreach($cols_for_pdf as $col): ?>
            <col style="width:<?= $colPercents[$col] ?? 5 ?>%;" />
        <?php endforeach; ?>
        <col style="width:<?= $qrPercent ?>%;" />
    </colgroup>
    <thead>
    <tr>
        <?php foreach($cols_for_pdf as $col) {
            $classes = '';
            if ($col === 'denominacion') $classes = 'col-denom';
            if ($col === 'observaciones') $classes = 'col-obs';
            if ($col === 'cantidad') $classes .= ' col-qty';
            if (in_array($col, ['fecha_compra','fecha_registro'])) $classes .= ' col-date';
            if ($col === 'serie' || $col === 'numero_documento') $classes .= ' small col-small';
            echo '<th class="'.trim($classes).'">'.$all_cols[$col].'</th>';
        } ?>
        <th class="col-qr">QR</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($datos as $fila): ?>
    <tr>
        <?php foreach($cols_for_pdf as $col) {
            $value = array_key_exists($col, $fila) ? (string)$fila[$col] : '';
            // Mejor formato para fechas
            if (in_array($col, ['fecha_compra','fecha_registro']) && $value) {
                $value = date('Y-m-d', strtotime($value));
            }
            $display = nl2br(htmlspecialchars($value));
            $titleAttr = '';
            // Añadir title si el contenido es largo (para ver completo en tooltip)
            if (mb_strlen($value, 'UTF-8') > 30) {
                $titleAttr = ' title="'.htmlspecialchars($value).'"';
            }
            echo '<td'.$titleAttr.'>'. $display .'</td>'; 
        } ?>
        <td class="col-qr" style="text-align:center;vertical-align:middle;">
            <img src="<?= $qr_imgs[$fila['id']] ?>" alt="QR" style="width:48px;height:48px;vertical-align:middle;border:0;margin:0;padding:0;background:transparent;">
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div style="margin-top:18px;font-size:0.95em;color:#444;text-align:right;">
    Reporte generado el <?= date('Y-m-d H:i') ?> por <b>Administrador</b><br>
    <span style="color:#b71c1c;font-size:0.98em;font-weight:bold;">USO INTERNO: Prohibida su difusión o reproducción fuera de la institución.</span>
</div>
<div style="margin-top:40px;display:flex;justify-content:space-between;gap:18px;max-width:900px;margin-left:auto;margin-right:auto;">
    <div style="text-align:center;flex:1;">
        <div style="border-bottom:1px solid #b71c1c;height:48px;margin-bottom:4px;"></div>
        <b>Director(a)</b>
    </div>
    <div style="text-align:center;flex:1;">
        <div style="border-bottom:1px solid #b71c1c;height:48px;margin-bottom:4px;"></div>
        <b>Coordinadora de Inventario (CIST)</b>
    </div>
    <div style="text-align:center;flex:1;">
        <div style="border-bottom:1px solid #b71c1c;height:48px;margin-bottom:4px;"></div>
        <b>Docente encargado de bienes</b>
    </div>
</div>
<?php
// Limpiar imágenes QR temporales después de renderizar el PDF
// Nota: actualmente `$qr_imgs` contiene data-URIs (base64). Solo intentamos
// eliminar archivos si hay rutas de archivo reales. Evitamos llamar a
// `file_exists`/`unlink` sobre data-URIs.
if (php_sapi_name() !== 'cli') {
    register_shutdown_function(function() use ($qr_imgs) {
        foreach ($qr_imgs as $img) {
            if (!is_string($img)) continue;
            // saltar data: URIs
            if (strpos($img, 'data:') === 0) continue;
            if (file_exists($img)) @unlink($img);
        }
    });
}

<?php
// Script CLI: dry_run_no_db.php
// Uso: php dry_run_no_db.php <csv_path> [--autofix]
// Ejecuta la validación y devuelve JSON similar al dry-run del servidor, sin acceder a la base de datos.

if ($argc < 2) {
    fwrite(STDERR, "Usage: php dry_run_no_db.php <csv_path> [--autofix]\n");
    exit(2);
}
$csv = $argv[1];
$autofix = in_array('--autofix', $argv, true) || in_array('-a', $argv, true);
if (!file_exists($csv)) {
    fwrite(STDERR, "CSV not found: $csv\n");
    exit(3);
}
$handle = fopen($csv, 'r');
if (!$handle) {
    fwrite(STDERR, "Cannot open CSV: $csv\n");
    exit(4);
}
// Detect delimiter from sample lines and handle BOM
$candidates = [',',';','\t','|'];
$counts = array_fill_keys($candidates, 0);
$maxLines = 8;
for ($i=0; $i < $maxLines && !feof($handle); $i++) {
    $ln = fgets($handle);
    if ($ln === false) break;
    foreach ($candidates as $d) $counts[$d] += substr_count($ln, $d);
}
arsort($counts);
$detected = array_keys($counts)[0];
if ($counts[$detected] === 0) $detected = ',';
rewind($handle);
// Read raw header line to strip BOM
$raw = fgets($handle);
if ($raw === false) {
    fwrite(STDERR, "Empty or invalid CSV header.\n");
    fclose($handle);
    exit(5);
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$encabezados = str_getcsv($raw, $detected);
// Normalizar encabezados: minusculas, sin acentos, no alfanum -> _
$normalized = [];
foreach ($encabezados as $h) {
    $k = mb_strtolower(trim($h));
    $k = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $k);
    $k = preg_replace('/[^a-z0-9_]/u', '_', $k);
    if ($k === 'aula') $k = 'aula_funcional';
    if ($k === 'denominacion' || $k === 'denominaci_n') $k = 'denominacion';
    if ($k === 'usuario' || $k === 'usuario_responsable') $k = 'usuario_responsable';
    $normalized[] = $k;
}
$encabezados = $normalized;
// Validar que la cabecera contenga columnas requeridas
$required_headers = ['aula_funcional','nivel','denominacion'];
$missing_headers = array_values(array_diff($required_headers, $encabezados));
if (!empty($missing_headers)) {
    $out = [
        'mode' => 'dry-run',
        'error' => 'missing_headers',
        'missing_headers' => $missing_headers,
        'message' => 'Faltan encabezados obligatorios: ' . implode(', ', $missing_headers)
    ];
    echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    fclose($handle);
    exit(0);
}

$headerCount = count($encabezados);
// PRE-SCAN
$total_rows = 0;
$bad_samples = [];
$invalid_rows = 0;
while (($peek = fgetcsv($handle, 0, $detected)) !== false) {
    $total_rows++;
    $cnt = is_array($peek) ? count($peek) : 0;
    if ($cnt !== $headerCount) {
        $invalid_rows++;
        if (count($bad_samples) < 10) {
            $bad_samples[] = ['line' => $total_rows + 1, 'columns_found' => $cnt, 'sample' => $peek];
        }
        continue;
    }
    // comprobar campos requeridos no vacíos
    $assoc = array_combine($encabezados, $peek);
    foreach ($required_headers as $rh) {
        if (!isset($assoc[$rh]) || trim((string)$assoc[$rh]) === '') {
            $invalid_rows++;
            if (count($bad_samples) < 10) {
                $bad_samples[] = ['line' => $total_rows + 1, 'columns_found' => $cnt, 'sample' => $peek, 'missing_field' => $rh];
            }
            break;
        }
    }
}
// Preparar respuesta si hay inválidos
$backup_url = null;
// Volver al inicio de datos (después de la cabecera)
$pos_after_header = ftell($handle);
fseek($handle, $pos_after_header);
$duplicados = 0; // no se comprueba BD aquí
$would_import = 0;
$sample_rows = [];
$by_aula = [];
$by_nivel = [];
$by_marca = [];
$by_estado = [];
$processing_start = microtime(true);
if ($invalid_rows > 0) {
    $proposed = [];
    if ($autofix && !empty($bad_samples)) {
        foreach ($bad_samples as $bs) {
            $orig = $bs['sample'];
            $cnt = is_array($orig) ? count($orig) : 0;
            $corrected = $orig;
            $action = '';
            $details = '';
            if ($cnt < $headerCount) {
                $pad = array_fill(0, $headerCount - $cnt, '');
                $corrected = array_merge($orig, $pad);
                $action = 'pad';
                $details = 'Se rellenaron ' . ($headerCount - $cnt) . ' columnas con valores vacíos.';
            } elseif ($cnt > $headerCount) {
                $first = array_slice($orig, 0, $headerCount-1);
                $extra = array_slice($orig, $headerCount-1);
                $last = implode(' | ', $extra);
                $corrected = array_merge($first, [$last]);
                $action = 'merge_extra';
                $details = 'Se unieron ' . count($extra) . ' columnas extra en la última columna.';
            }
            $assoc = [];
            for ($i=0;$i<$headerCount;$i++) {
                $assoc[$encabezados[$i]] = isset($corrected[$i]) ? $corrected[$i] : '';
            }
            $proposed[] = [
                'line' => $bs['line'],
                'columns_found' => $bs['columns_found'],
                'action' => $action,
                'details' => $details,
                'original_sample' => $orig,
                'corrected_row' => $assoc
            ];
        }
    }
    $out = [
        'mode' => 'dry-run',
        'total_rows' => $total_rows,
        'rows_invalid' => $invalid_rows,
        'rows_valid' => max(0, $total_rows - $invalid_rows),
        'bad_samples' => $bad_samples,
        'headers' => $encabezados,
        'autofix_available' => true,
        'autofix_enabled' => $autofix,
        'backup_url' => $backup_url,
        'proposed_corrections' => $proposed,
        'message' => 'Se detectaron filas con columnas inconsistentes. Activa --autofix para ver propuestas.'
    ];
    echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    fclose($handle);
    exit(0);
}
// Si no hay inválidos, procesar para contar duplicados hipotéticos y would_import (sin BD)
while (($data = fgetcsv($handle, 0, $detected)) !== false) {
    $tmp = array_combine($encabezados, $data);
    $expected = ['nivel','aula_funcional','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro','cantidad'];
    $row = [];
    foreach ($expected as $k) { $row[$k] = isset($tmp[$k]) ? $tmp[$k] : ''; }
    // Sin DB no se puede detectar duplicados reales; simulamos que nada es duplicado
    $would_import++;
    if (count($sample_rows) < 10) $sample_rows[] = ['row' => $row, 'status' => 'would_import'];
    if (!empty($row['aula_funcional'])) $by_aula[$row['aula_funcional']] = ($by_aula[$row['aula_funcional']] ?? 0) + 1;
    if (!empty($row['nivel'])) $by_nivel[$row['nivel']] = ($by_nivel[$row['nivel']] ?? 0) + 1;
    if (!empty($row['marca'])) $by_marca[$row['marca']] = ($by_marca[$row['marca']] ?? 0) + 1;
    if (!empty($row['estado'])) $by_estado[$row['estado']] = ($by_estado[$row['estado']] ?? 0) + 1;
}
$processing_end = microtime(true);
$processing_ms = (int)(($processing_end - $processing_start) * 1000);
$out = [
    'mode' => 'dry-run',
    'total_rows' => $total_rows,
    'rows_valid' => $total_rows - $invalid_rows,
    'rows_invalid' => $invalid_rows,
    'would_import' => $would_import,
    'duplicados' => $duplicados,
    'by_aula' => $by_aula,
    'by_nivel' => $by_nivel,
    'by_marca' => $by_marca,
    'by_estado' => $by_estado,
    'file_hash' => null,
    'sync_id_candidate' => null,
    'tiempo_procesamiento_ms' => $processing_ms,
    'sample' => $sample_rows
];
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
fclose($handle);
exit(0);

<?php
// parse_test.php - Parse test_import.csv using same normalization/safe_assoc logic
$csv = __DIR__ . '/test_import.csv';
if (!file_exists($csv)) { echo "CSV no encontrado: $csv\n"; exit(1); }
$handle = fopen($csv, 'r');
if (!$handle) { echo "No se pudo abrir CSV\n"; exit(1); }
// read header
$rawHeader = fgets($handle);
$rawHeader = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader);
$encabezados = str_getcsv($rawHeader, ',');
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

function safe_assoc($keys, $values) {
    // Versión simple: si menos valores, rellenar con '' ; si más, juntar extras en última columna
    $kcnt = count($keys); $vcnt = is_array($values)?count($values):0;
    if ($kcnt === $vcnt) return array_combine($keys, $values);
    // fewer values
    if ($vcnt < $kcnt) {
        $pad = array_fill(0, $kcnt - $vcnt, '');
        return array_combine($keys, array_merge($values, $pad));
    }
    // more values: merge extras into last key
    $first = array_slice($values, 0, $kcnt-1);
    $extra = array_slice($values, $kcnt-1);
    $last = implode(' | ', $extra);
    return array_combine($keys, array_merge($first, [$last]));
}

$line = 1;
$expected = ['nivel','aula_funcional','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro','cantidad'];
while (($data = fgetcsv($handle)) !== false) {
    $line++;
    $tmp = safe_assoc($encabezados, $data);
    $row = [];
    foreach ($expected as $k) { $row[$k] = isset($tmp[$k]) ? $tmp[$k] : ''; }
    $cantidad = isset($row['cantidad']) && $row['cantidad'] !== '' ? intval($row['cantidad']) : 1;
    echo "Linea $line: denominacion='" . $row['denominacion'] . "' cantidad='" . $row['cantidad'] . "' => parsed_cantidad={$cantidad}\n";
}
fclose($handle);

?>
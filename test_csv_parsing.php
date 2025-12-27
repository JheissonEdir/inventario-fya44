<?php
// Test para simular el procesamiento del CSV

function safe_assoc($keys, $values) {
    $kcnt = is_array($keys) ? count($keys) : 0;
    $vcnt = is_array($values) ? count($values) : 0;
    if ($kcnt === 0) return [];
    if ($kcnt === $vcnt) return array_combine($keys, $values);
    if ($vcnt < $kcnt) {
        $pad = array_fill(0, $kcnt - $vcnt, '');
        $values = array_merge($values, $pad);
        return array_combine($keys, $values);
    }
    // $vcnt > $kcnt: fusionar extras en la última clave
    $first = array_slice($values, 0, $kcnt - 1);
    $extra = array_slice($values, $kcnt - 1);
    $last = implode(' | ', $extra);
    $values = array_merge($first, [$last]);
    return array_combine($keys, $values);
}

// Simular lectura del CSV
$csvFile = __DIR__ . '/test_aulas_CORRECTO.csv';
$handle = fopen($csvFile, 'r');

// Leer encabezado
$rawHeader = fgets($handle);
$encabezados_raw = str_getcsv($rawHeader, ',');

echo "=== ENCABEZADOS ORIGINALES ===\n";
print_r($encabezados_raw);
echo "\n";

// Normalizar encabezados (mismo código que inventario_central.php)
$normalized = [];
foreach ($encabezados_raw as $h) {
    $k = mb_strtolower(trim($h));
    $k = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $k);
    $k = preg_replace('/[^a-z0-9_]/u', '_', $k);
    if ($k === 'aula') $k = 'aula_funcional';
    if ($k === 'denominacion' || $k === 'denominaci_n') $k = 'denominacion';
    if ($k === 'usuario' || $k === 'usuario_responsable') $k = 'usuario_responsable';
    $normalized[] = $k;
}
$encabezados = $normalized;

echo "=== ENCABEZADOS NORMALIZADOS ===\n";
print_r($encabezados);
echo "\n";

// Leer primera fila de datos (Aula 18)
$rawLine = fgets($handle);
$data = str_getcsv($rawLine, ',');

echo "=== DATOS DE LA PRIMERA FILA (CSV) ===\n";
print_r($data);
echo "\n";

// Aplicar safe_assoc
$tmp = safe_assoc($encabezados, $data);

echo "=== ARRAY ASOCIATIVO (tmp) ===\n";
print_r($tmp);
echo "\n";

// Crear array $row según expected
$expected = ['nivel','aula_funcional','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro','cantidad'];
$row = [];
foreach ($expected as $k) { 
    $row[$k] = isset($tmp[$k]) ? $tmp[$k] : ''; 
}

echo "=== ARRAY ROW (final que se inserta en BD) ===\n";
print_r($row);
echo "\n";

echo "=== VALORES ESPECÍFICOS ===\n";
echo "nivel: '{$row['nivel']}'\n";
echo "aula_funcional: '{$row['aula_funcional']}'\n";
echo "denominacion: '{$row['denominacion']}'\n";
echo "ubicacion: '{$row['ubicacion']}'\n";

fclose($handle);

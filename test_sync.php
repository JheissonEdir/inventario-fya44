<?php
// test_sync.php - envía un CSV de prueba al inventario_central.php
$central = 'http://localhost/Inventario%20Escolar/inventario_central.php';
$token = 'TOKEN_TEST_LOCAL';
$anio = date('Y');
$sync_id = 'test_'.preg_replace('/[^a-z0-9_\-]/i','_', uniqid());
$source_user = 'test_user';

// Crear CSV temporal
$tmp = tempnam(sys_get_temp_dir(), 'invtest');
$f = fopen($tmp, 'w');
$headers = ['nivel','aula_funcional','cantidad','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro'];
fputcsv($f, $headers);
// Dos filas de prueba
fputcsv($f, ['Primaria','Aula 1',2,'Mesa plegable','MarcaX','M1','Mobiliario','Marron','S123','','','','doc1','2023-01-10','100','Bueno','UGEL','Prueba','Profesor A','A1','2025-11-22']);
fputcsv($f, ['Primaria','Aula 1',1,'Silla plástica','MarcaY','S2','Mobiliario','Blanco','S124','','','','doc2','2024-03-05','101','Bueno','Fe y Alegría','Prueba','Profesor B','A1','2025-11-22']);
fclose($f);

// Preparar POST
$cfile = new CURLFile($tmp, 'text/csv', 'test_inventario.csv');
$post = [
    'importar' => '1',
    'sync_token' => $token,
    'anio' => $anio,
    'sync_id' => $sync_id,
    'source_user' => $source_user,
    'csv' => $cfile
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $central);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$resp = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);

echo "HTTP: $http\n";
if ($err) echo "CURL ERROR: $err\n";
echo "RESPUESTA:\n";
echo $resp . "\n";

// Mostrar último fragmento de sync_log.json si existe
$log = __DIR__ . '/sync_log.json';
if (file_exists($log)) {
    $data = json_decode(file_get_contents($log), true) ?: [];
    echo "\nSYNC_LOG (últimas 3):\n";
    $last = array_slice($data, -3);
    echo json_encode($last, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "\nNo existe sync_log.json\n";
}
?>
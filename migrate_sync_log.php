<?php
// migrate_sync_log.php
// Script CLI para crear tabla `syncs` y migrar registros de sync_log.json a la base de datos.
// Uso: php migrate_sync_log.php

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: '.$conn->connect_error);
}

// Crear tabla syncs si no existe
$sql_create = "CREATE TABLE IF NOT EXISTS syncs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ts DATETIME,
    sync_id VARCHAR(255),
    file_hash VARCHAR(255),
    anio INT DEFAULT NULL,
    inventario_id INT DEFAULT NULL,
    importados INT DEFAULT 0,
    duplicados INT DEFAULT 0,
    source_user VARCHAR(255),
    uploader_ip VARCHAR(45)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if (!$conn->query($sql_create)) {
    die('Error creando tabla syncs: '.$conn->error);
}

$sync_log_file = __DIR__ . '/sync_log.json';
if (!file_exists($sync_log_file)) {
    echo "No hay archivo sync_log.json para migrar.\n";
    exit(0);
}
$entries = json_decode(file_get_contents($sync_log_file), true) ?: [];
if (!$entries) {
    echo "No hay registros en sync_log.json.\n";
    exit(0);
}

$inserted = 0;
foreach ($entries as $e) {
    $ts = isset($e['timestamp']) ? date('Y-m-d H:i:s', strtotime($e['timestamp'])) : date('Y-m-d H:i:s');
    $sync_id = $conn->real_escape_string($e['sync_id'] ?? '');
    $file_hash = $conn->real_escape_string($e['hash'] ?? '');
    $anio = isset($e['anio']) ? intval($e['anio']) : null;
    $inventario_id = isset($e['inventario_id']) ? intval($e['inventario_id']) : null;
    $importados = isset($e['importados']) ? intval($e['importados']) : 0;
    $duplicados = isset($e['duplicados']) ? intval($e['duplicados']) : 0;
    $source_user = $conn->real_escape_string($e['source_user'] ?? '');
    $uploader_ip = $conn->real_escape_string($e['uploader_ip'] ?? '');
    $stmt = $conn->prepare("INSERT INTO syncs (ts, sync_id, file_hash, anio, inventario_id, importados, duplicados, source_user, uploader_ip) VALUES (?,?,?,?,?,?,?,?,?)");
    if ($stmt) {
        $stmt->bind_param('sssiissss', $ts, $sync_id, $file_hash, $anio, $inventario_id, $importados, $duplicados, $source_user, $uploader_ip);
        $stmt->execute();
        $stmt->close();
        $inserted++;
    }
}

echo "Migrados $inserted registros a la tabla syncs.\n";
$conn->close();

?>
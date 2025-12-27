<?php
// Script de prueba para crear un nuevo año y copiar inventario
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);

$anio_actual = 2025;
$anio_nuevo = 2026;

// Buscar inventario actual
$res = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_actual");
if (!$res || !$res->num_rows) die('No existe inventario para el año actual.');
$row = $res->fetch_assoc();
$inventario_id = $row['id'];

// Verificar si ya existe el año nuevo
$res2 = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_nuevo");
if ($res2 && $res2->num_rows) die('El año nuevo ya existe.');

// Crear año nuevo
$conn->query("INSERT INTO inventarios (anio, estado) VALUES ($anio_nuevo, 'activo')");
$nuevo_id = $conn->insert_id;

// Copiar bienes
$res3 = $conn->query("SELECT * FROM inventario WHERE inventario_id=$inventario_id");
$copiados = 0;
if ($res3 && $res3->num_rows > 0) {
    $conn->query("INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, inventario_id) SELECT nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, $nuevo_id FROM inventario WHERE inventario_id=$inventario_id");
    $copiados = $res3->num_rows;
}
echo "Año $anio_nuevo creado. Bienes copiados: $copiados";

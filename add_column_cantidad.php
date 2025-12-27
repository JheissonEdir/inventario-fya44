<?php
// add_column_cantidad.php
// Añade la columna `cantidad` a la tabla `inventario` si no existe.
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo "Error de conexión: " . $conn->connect_error . "\n";
    exit(1);
}

$table = 'inventario';
$col = 'cantidad';
$res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
if ($res && $res->num_rows > 0) {
    echo "La columna '$col' ya existe en la tabla '$table'. Nada que hacer.\n";
    $conn->close();
    exit(0);
}

$sql = "ALTER TABLE `$table` ADD COLUMN `$col` INT NOT NULL DEFAULT 1 AFTER `denominacion`"; // posicion opcional
if ($conn->query($sql) === TRUE) {
    echo "Columna '$col' agregada correctamente a la tabla '$table'.\n";
} else {
    echo "Error al agregar la columna: " . $conn->error . "\n";
}
$conn->close();
?>
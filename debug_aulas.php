<?php
// Debug: mostrar qué valor se está guardando en aula_funcional

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

// Obtener los últimos 20 registros insertados en el inventario
$sql = "SELECT id, nivel, aula_funcional, denominacion, ubicacion, fecha_registro 
        FROM inventario 
        WHERE aula_funcional IN ('Aula 18', 'Aula 19', '18', '19', 'Aula 1', '1')
        ORDER BY id DESC 
        LIMIT 30";

$result = $conn->query($sql);

echo "<h3>Últimos registros con aulas 1, 18 o 19:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nivel</th><th>Aula Funcional</th><th>Denominación</th><th>Ubicación</th><th>Fecha Registro</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['nivel']}</td>";
    echo "<td style='background: " . (in_array($row['aula_funcional'], ['1', 'Aula 1']) ? '#ffcccc' : '#ccffcc') . "'>{$row['aula_funcional']}</td>";
    echo "<td>{$row['denominacion']}</td>";
    echo "<td>{$row['ubicacion']}</td>";
    echo "<td>{$row['fecha_registro']}</td>";
    echo "</tr>";
}

echo "</table>";

$conn->close();
?>

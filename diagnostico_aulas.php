<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

echo "<h2>Diagnóstico de Aulas en la Base de Datos</h2>";

// 1. Contar registros por aula_funcional
echo "<h3>1. Conteo de registros por aula_funcional:</h3>";
$sql = "SELECT aula_funcional, COUNT(*) as total 
        FROM inventario 
        GROUP BY aula_funcional 
        ORDER BY aula_funcional";
$result = $conn->query($sql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Aula Funcional</th><th>Total Registros</th></tr>";
while ($row = $result->fetch_assoc()) {
    $bg = '';
    if (in_array($row['aula_funcional'], ['Aula 18', 'Aula 19'])) $bg = " style='background:#ccffcc'";
    if (in_array($row['aula_funcional'], ['Aula 1', '1'])) $bg = " style='background:#ffcccc'";
    echo "<tr$bg><td>{$row['aula_funcional']}</td><td>{$row['total']}</td></tr>";
}
echo "</table><br>";

// 2. Registros con usuario "Yudit Rojas Rodriguez" (del CSV que enviaste)
echo "<h3>2. Registros de 'Yudit Rojas Rodriguez' (últimos 20):</h3>";
$sql = "SELECT id, nivel, aula_funcional, denominacion, ubicacion, fecha_registro 
        FROM inventario 
        WHERE usuario_responsable LIKE '%Yudit%' 
        ORDER BY id DESC 
        LIMIT 20";
$result = $conn->query($sql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nivel</th><th>Aula Funcional</th><th>Denominación</th><th>Ubicación</th><th>Fecha Registro</th></tr>";
while ($row = $result->fetch_assoc()) {
    $bg = '';
    if (in_array($row['aula_funcional'], ['Aula 18', 'Aula 19'])) $bg = " style='background:#ccffcc'";
    if (in_array($row['aula_funcional'], ['Aula 1', '1'])) $bg = " style='background:#ffcccc'";
    echo "<tr$bg>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['nivel']}</td>";
    echo "<td><b>{$row['aula_funcional']}</b></td>";
    echo "<td>{$row['denominacion']}</td>";
    echo "<td>{$row['ubicacion']}</td>";
    echo "<td>{$row['fecha_registro']}</td>";
    echo "</tr>";
}
echo "</table><br>";

// 3. Buscar específicamente registros con denominaciones del CSV
echo "<h3>3. Registros específicos del CSV (Escritorio, Mesas, Sillas en Aula 18/19):</h3>";
$sql = "SELECT id, nivel, aula_funcional, denominacion, cantidad, marca, usuario_responsable, fecha_registro 
        FROM inventario 
        WHERE nivel = 'Secundaria' 
        AND denominacion IN ('Escritorio', 'Mesas', 'Sillas', 'carpeta unipersonal', 'Cañón multimedia')
        ORDER BY id DESC 
        LIMIT 30";
$result = $conn->query($sql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nivel</th><th>Aula Funcional</th><th>Denominación</th><th>Cantidad</th><th>Marca</th><th>Usuario</th><th>Fecha</th></tr>";
while ($row = $result->fetch_assoc()) {
    $bg = '';
    if (in_array($row['aula_funcional'], ['Aula 18', 'Aula 19'])) $bg = " style='background:#ccffcc'";
    if (in_array($row['aula_funcional'], ['Aula 1', '1'])) $bg = " style='background:#ffcccc'";
    echo "<tr$bg>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['nivel']}</td>";
    echo "<td><b>{$row['aula_funcional']}</b></td>";
    echo "<td>{$row['denominacion']}</td>";
    echo "<td>{$row['cantidad']}</td>";
    echo "<td>{$row['marca']}</td>";
    echo "<td>{$row['usuario_responsable']}</td>";
    echo "<td>{$row['fecha_registro']}</td>";
    echo "</tr>";
}
echo "</table><br>";

echo "<p><b>Verde</b> = Aula 18 o 19 (correcto según el CSV)<br>";
echo "<b>Rojo</b> = Aula 1 (incorrecto)</p>";

$conn->close();
?>

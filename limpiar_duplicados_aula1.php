<?php
// Script para eliminar duplicados erróneos del Aula 1

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

echo "<div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 6px;'>";
echo "<h3 style='color: #856404; margin: 0 0 10px 0;'>⚠️ Nueva Herramienta Disponible</h3>";
echo "<p style='margin: 0; color: #856404;'>En lugar de eliminar registros, ahora puedes <strong>reubicarlos</strong> al aula correcta sin perder información.</p>";
echo "<p style='margin: 10px 0 0 0;'><a href='reubicar_items.php' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block;'>🔄 Ir a Reubicar Items</a></p>";
echo "</div>";

echo "<h2>🧹 Limpieza de Duplicados en Aula 1</h2>";
echo "<p style='color: #666;'><em>Esta herramienta elimina registros. Considera usar la herramienta de reubicación para mover items al aula correcta.</em></p>";

// Buscar registros en Aula 1 cuyo usuario responsable sea "Yudit Rojas Rodriguez"
// (estos son los que se importaron mal)
$sql = "SELECT id, nivel, aula_funcional, denominacion, marca, usuario_responsable, ubicacion, fecha_registro 
        FROM inventario 
        WHERE aula_funcional = 'Aula 1' 
        AND usuario_responsable LIKE '%Yudit%Rojas%'
        AND nivel = 'Secundaria'
        ORDER BY id DESC";

$result = $conn->query($sql);
$registros_a_eliminar = [];

echo "<h3>Registros encontrados en Aula 1 (que deberían estar en Aula 18/19):</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nivel</th><th>Aula</th><th>Denominación</th><th>Marca</th><th>Usuario</th><th>Ubicación</th><th>Fecha</th></tr>";

while ($row = $result->fetch_assoc()) {
    $registros_a_eliminar[] = $row['id'];
    echo "<tr style='background:#ffcccc'>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['nivel']}</td>";
    echo "<td><b>{$row['aula_funcional']}</b></td>";
    echo "<td>{$row['denominacion']}</td>";
    echo "<td>{$row['marca']}</td>";
    echo "<td>{$row['usuario_responsable']}</td>";
    echo "<td>{$row['ubicacion']}</td>";
    echo "<td>{$row['fecha_registro']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p><b>Total de registros a eliminar: " . count($registros_a_eliminar) . "</b></p>";

if (count($registros_a_eliminar) > 0) {
    echo "<form method='post' style='margin:20px 0'>";
    echo "<input type='hidden' name='ids' value='" . implode(',', $registros_a_eliminar) . "'>";
    echo "<button type='submit' name='confirmar' style='padding:12px 24px; background:#c62828; color:white; border:none; border-radius:6px; cursor:pointer; font-size:16px;'>❌ Eliminar estos registros erróneos</button>";
    echo "</form>";
}

// Si se confirmó la eliminación
if (isset($_POST['confirmar']) && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $ids = array_map('intval', $ids);
    
    if (count($ids) > 0) {
        $ids_list = implode(',', $ids);
        $sql_delete = "DELETE FROM inventario WHERE id IN ($ids_list)";
        
        if ($conn->query($sql_delete)) {
            echo "<div style='background:#c8e6c9; padding:15px; border-radius:6px; margin:20px 0;'>";
            echo "<h3 style='color:#2e7d32; margin:0;'>✅ Eliminación exitosa</h3>";
            echo "<p>Se eliminaron " . count($ids) . " registros erróneos del Aula 1.</p>";
            echo "<p><a href='diagnostico_aulas.php' style='color:#1976d2;'>Ver diagnóstico actualizado</a></p>";
            echo "</div>";
        } else {
            echo "<div style='background:#ffcdd2; padding:15px; border-radius:6px; margin:20px 0;'>";
            echo "<h3 style='color:#c62828; margin:0;'>❌ Error</h3>";
            echo "<p>No se pudieron eliminar los registros: " . $conn->error . "</p>";
            echo "</div>";
        }
    }
}

// Mostrar resumen actual
echo "<hr style='margin:30px 0;'>";
echo "<h3>📊 Resumen actual por aula:</h3>";
$sql_resumen = "SELECT aula_funcional, COUNT(*) as total 
                FROM inventario 
                WHERE nivel = 'Secundaria'
                GROUP BY aula_funcional 
                ORDER BY aula_funcional";
$result_resumen = $conn->query($sql_resumen);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Aula</th><th>Total Registros</th></tr>";
while ($row = $result_resumen->fetch_assoc()) {
    $bg = '';
    if ($row['aula_funcional'] == 'Aula 1') $bg = " style='background:#ffcccc'";
    if (in_array($row['aula_funcional'], ['Aula 18', 'Aula 19'])) $bg = " style='background:#ccffcc'";
    echo "<tr$bg><td>{$row['aula_funcional']}</td><td>{$row['total']}</td></tr>";
}
echo "</table>";

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
table { border-collapse: collapse; margin: 10px 0; background: white; }
th { background: #b71c1c; color: white; padding: 8px; }
td { padding: 8px; }
</style>

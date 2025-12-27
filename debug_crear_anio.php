<?php
// Página de debug para crear año nuevo y mostrar detalles del proceso

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

echo "<h2>Debug: Crear nuevo año</h2>";

// Mostrar años existentes
echo "<h3>Años existentes:</h3>";
$res = $conn->query("SELECT * FROM inventarios ORDER BY anio DESC");
while ($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']}, Año: {$row['anio']}, Estado: {$row['estado']}<br>";
}

// Simular creación de año nuevo
if (isset($_POST['crear_anio'])) {
    $anio_nuevo = intval($_POST['anio_nuevo']);
    echo "<hr><h3>Intentando crear año: $anio_nuevo</h3>";
    
    // Verificar si existe
    $existe = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_nuevo");
    if ($existe && $existe->num_rows > 0) {
        echo "<span style='color:red;'>❌ El año $anio_nuevo ya existe</span><br>";
    } else {
        echo "✅ El año $anio_nuevo no existe, creando...<br>";
        
        // Crear año
        $result = $conn->query("INSERT INTO inventarios (anio, estado) VALUES ($anio_nuevo, 'activo')");
        if ($result) {
            $nuevo_id = $conn->insert_id;
            echo "✅ Año creado con ID: $nuevo_id<br>";
            
            // Buscar inventario actual para copiar
            $anio_actual = 2025;
            $res_actual = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_actual");
            if ($res_actual && $res_actual->num_rows > 0) {
                $row_actual = $res_actual->fetch_assoc();
                $inventario_id_actual = $row_actual['id'];
                echo "✅ Inventario actual encontrado (ID: $inventario_id_actual)<br>";
                
                // Contar bienes a copiar
                $res_bienes = $conn->query("SELECT COUNT(*) as total FROM inventario WHERE inventario_id=$inventario_id_actual");
                $row_bienes = $res_bienes->fetch_assoc();
                $total_bienes = $row_bienes['total'];
                echo "✅ Bienes a copiar: $total_bienes<br>";
                
                if ($total_bienes > 0) {
                    // Copiar bienes
                    $copy_result = $conn->query("INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, inventario_id) SELECT nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, $nuevo_id FROM inventario WHERE inventario_id=$inventario_id_actual");
                    
                    if ($copy_result) {
                        echo "✅ Bienes copiados exitosamente<br>";
                    } else {
                        echo "<span style='color:red;'>❌ Error al copiar bienes: " . $conn->error . "</span><br>";
                    }
                } else {
                    echo "⚠️ No hay bienes para copiar<br>";
                }
            } else {
                echo "<span style='color:orange;'>⚠️ No se encontró inventario del año $anio_actual</span><br>";
            }
        } else {
            echo "<span style='color:red;'>❌ Error al crear año: " . $conn->error . "</span><br>";
        }
    }
    
    echo "<hr><h3>Años después de la operación:</h3>";
    $res2 = $conn->query("SELECT * FROM inventarios ORDER BY anio DESC");
    while ($row = $res2->fetch_assoc()) {
        echo "ID: {$row['id']}, Año: {$row['anio']}, Estado: {$row['estado']}<br>";
    }
}
?>

<form method="post">
    <label>Año nuevo: <input type="number" name="anio_nuevo" min="2020" max="2100" value="2027" required></label>
    <button type="submit" name="crear_anio">Crear y depurar</button>
</form>

<hr>
<a href="inventario_central.php">Volver al inventario</a>
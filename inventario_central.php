<?php
// inventario_central.php
// Página para importar CSV y mostrar inventario centralizado

// Configuración de conexión
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

$mensaje = '';

// Procesar eliminación
if (isset($_POST['eliminar']) && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $conn->query("DELETE FROM inventario WHERE id=$id");
}

// Procesar actualización
if (isset($_POST['actualizar']) && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $campos = [
        'nivel', 'aula_funcional', 'denominacion', 'marca', 'modelo', 'tipo', 'color', 'serie',
        'largo', 'ancho', 'alto', 'documento_alta', 'fecha_compra', 'numero_documento', 'estado',
        'procedencia', 'observaciones', 'usuario_responsable', 'ubicacion', 'fecha_registro'
    ];
    $set = [];
    foreach ($campos as $campo) {
        $valor = $conn->real_escape_string($_POST[$campo]);
        $set[] = "$campo='" . $valor . "'";
    }
    $sql = "UPDATE inventario SET ".implode(',', $set)." WHERE id=$id";
    $conn->query($sql);
}

// Procesar importación CSV
if (isset($_POST['importar']) && isset($_FILES['csv']) && $_FILES['csv']['error'] == 0) {
    $archivo = $_FILES['csv']['tmp_name'];
    $handle = fopen($archivo, 'r');
    if ($handle) {
        $encabezados = fgetcsv($handle);
        $importados = 0;
        $duplicados = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($encabezados, $data);
            // Criterio de duplicidad: nivel, aula_funcional, denominacion, serie
            $nivel = $conn->real_escape_string($row['nivel']);
            $aula = $conn->real_escape_string($row['aula_funcional']);
            $denom = $conn->real_escape_string($row['denominacion']);
            $serie = $conn->real_escape_string($row['serie']);
            $sql_check = "SELECT id FROM inventario WHERE nivel='$nivel' AND aula_funcional='$aula' AND denominacion='$denom' AND serie='$serie' LIMIT 1";
            $res = $conn->query($sql_check);
            if ($res && $res->num_rows > 0) {
                $duplicados++;
                continue;
            }
            // Insertar
            $stmt = $conn->prepare("INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param(
                'ssssssssddssssssssss',
                $row['nivel'],
                $row['aula_funcional'],
                $row['denominacion'],
                $row['marca'],
                $row['modelo'],
                $row['tipo'],
                $row['color'],
                $row['serie'],
                $row['largo'] !== '' ? (float)$row['largo'] : null,
                $row['ancho'] !== '' ? (float)$row['ancho'] : null,
                $row['alto'] !== '' ? (float)$row['alto'] : null,
                $row['documento_alta'],
                $row['fecha_compra'],
                $row['numero_documento'],
                $row['estado'],
                $row['procedencia'],
                $row['observaciones'],
                $row['usuario_responsable'],
                $row['ubicacion'],
                $row['fecha_registro']
            );
            $stmt->execute();
            $importados++;
        }
        fclose($handle);
        $mensaje = "Importados: $importados. Duplicados: $duplicados.";
    } else {
        $mensaje = 'No se pudo leer el archivo CSV.';
    }
}

// Consultar inventario
$result = $conn->query("SELECT * FROM inventario ORDER BY nivel, aula_funcional, denominacion");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Colegio Fe y Alegría 44 - 2025</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fff; }
        h2 { color: #b71c1c; text-align: center; margin-bottom: 0; }
        h3 { color: #b71c1c; text-align: center; margin-top: 8px; }
        .subtitulo { text-align: center; color: #b71c1c; font-size: 1.1em; margin-bottom: 18px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; background: #fff; }
        th, td { border: 1px solid #e57373; padding: 6px; text-align: left; }
        th { background: #ffcdd2; color: #b71c1c; }
        input[type=file] { margin: 8px 0; }
        .btn { padding: 6px 12px; margin: 4px; background: #b71c1c; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #d32f2f; }
        .msg { margin: 10px 0; color: #006400; }
        footer { margin-top:40px; text-align:center; color:#b71c1c; font-size:1em; }
        hr { border: 0; border-top: 2px solid #b71c1c; }
    </style>
</head>
<body>
    <h2>Inventario del Colegio Fe y Alegría 44</h2>
    <div class="subtitulo">Inventario 2025</div>
    <form method="post" enctype="multipart/form-data">
        <label>Importar archivo CSV:
            <input type="file" name="csv" accept=".csv" required>
        </label>
        <button type="submit" name="importar" class="btn">Importar</button>
    </form>
    <?php if ($mensaje): ?>
        <div class="msg"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <h3>Inventario Actual</h3>
    <table>
        <thead>
        <tr>
            <th>Nivel</th><th>Aula</th><th>Denominación</th><th>Marca</th><th>Modelo</th><th>Tipo</th><th>Color</th><th>Serie</th><th>Largo</th><th>Ancho</th><th>Alto</th><th>Doc. Alta</th><th>Fecha Compra</th><th>N° Doc</th><th>Estado</th><th>Procedencia</th><th>Obs.</th><th>Usuario</th><th>Ubicación</th><th>Fecha Registro</th><th>Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php 
        $total_registros = 0;
        while ($row = $result->fetch_assoc()): 
            $total_registros++;
        ?>
        <tr>
            <td><?= htmlspecialchars($row['nivel']) ?></td>
            <td><?= htmlspecialchars($row['aula_funcional']) ?></td>
            <td><?= htmlspecialchars($row['denominacion']) ?></td>
            <td><?= htmlspecialchars($row['marca']) ?></td>
            <td><?= htmlspecialchars($row['modelo']) ?></td>
            <td><?= htmlspecialchars($row['tipo']) ?></td>
            <td><?= htmlspecialchars($row['color']) ?></td>
            <td><?= htmlspecialchars($row['serie']) ?></td>
            <td><?= htmlspecialchars($row['largo']) ?></td>
            <td><?= htmlspecialchars($row['ancho']) ?></td>
            <td><?= htmlspecialchars($row['alto']) ?></td>
            <td><?= htmlspecialchars($row['documento_alta']) ?></td>
            <td><?= htmlspecialchars($row['fecha_compra']) ?></td>
            <td><?= htmlspecialchars($row['numero_documento']) ?></td>
            <td><?= htmlspecialchars($row['estado']) ?></td>
            <td><?= htmlspecialchars($row['procedencia']) ?></td>
            <td><?= htmlspecialchars($row['observaciones']) ?></td>
            <td><?= htmlspecialchars($row['usuario_responsable']) ?></td>
            <td><?= htmlspecialchars($row['ubicacion']) ?></td>
            <td><?= htmlspecialchars($row['fecha_registro']) ?></td>
            <td>
                <button onclick="editarFila(<?= $row['id'] ?>)">Editar</button>
                <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este registro?');">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <button type="submit" name="eliminar">Eliminar</button>
                </form>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <div style="margin: 18px 0; font-weight: bold; text-align: right;">Total de registros: <?= $total_registros ?></div>

    <footer>
        <hr style="margin:18px 0;">
        <b>Sistema realizado por Max System</b><br>
        <span>Inventario del Colegio Fe y Alegría 44 &mdash; 2025</span><br>
        &copy; <?= date('Y') ?> Derechos reservados
    </footer>

    <!-- Modal de edición -->
    <div id="modalEditar" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:#0008; z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:500px; width:95vw; position:relative;">
            <button onclick="cerrarModal()" style="position:absolute; top:8px; right:8px; font-size:1.2em;">&times;</button>
            <h3>Editar Inventario</h3>
            <form method="post" id="formEditar">
                <input type="hidden" name="id" id="edit_id">
                <label>Nivel:
                    <select name="nivel" id="edit_nivel" required>
                        <option value="Inicial">Inicial</option>
                        <option value="Primaria">Primaria</option>
                        <option value="Secundaria">Secundaria</option>
                    </select>
                </label>
                <label>Aula Funcional: <input name="aula_funcional" id="edit_aula_funcional" required></label>
                <label>Denominación: <input name="denominacion" id="edit_denominacion" required></label>
                <label>Marca: <input name="marca" id="edit_marca"></label>
                <label>Modelo: <input name="modelo" id="edit_modelo"></label>
                <label>Tipo:
                    <select name="tipo" id="edit_tipo" required>
                        <option value="Mobiliario">Mobiliario</option>
                        <option value="Equipo">Equipo</option>
                        <option value="Material">Material</option>
                        <option value="Otro">Otro</option>
                    </select>
                </label>
                <label>Color: <input name="color" id="edit_color"></label>
                <label>Serie: <input name="serie" id="edit_serie"></label>
                <label>Largo: <input name="largo" id="edit_largo" type="number" step="0.01"></label>
                <label>Ancho: <input name="ancho" id="edit_ancho" type="number" step="0.01"></label>
                <label>Alto: <input name="alto" id="edit_alto" type="number" step="0.01"></label>
                <label>Documento de Alta: <input name="documento_alta" id="edit_documento_alta"></label>
                <label>Fecha de Compra: <input name="fecha_compra" id="edit_fecha_compra" type="date"></label>
                <label>Número de Documento: <input name="numero_documento" id="edit_numero_documento"></label>
                <label>Estado:
                    <select name="estado" id="edit_estado" required>
                        <option value="Bueno">Bueno</option>
                        <option value="Regular">Regular</option>
                        <option value="Malo">Malo</option>
                    </select>
                </label>
                <label>Procedencia:
                    <select name="procedencia" id="edit_procedencia" required>
                        <option value="UGEL">UGEL</option>
                        <option value="Fe y Alegría">Fe y Alegría</option>
                        <option value="Jesuitas">Jesuitas</option>
                        <option value="Otra">Otra</option>
                    </select>
                </label>
                <label>Observaciones: <input name="observaciones" id="edit_observaciones"></label>
                <label>Usuario Responsable: <input name="usuario_responsable" id="edit_usuario_responsable"></label>
                <label>Ubicación: <input name="ubicacion" id="edit_ubicacion"></label>
                <label>Fecha Registro: <input name="fecha_registro" id="edit_fecha_registro" type="date"></label>
                <button type="submit" name="actualizar" class="btn">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <script>
    // Guardar todos los datos de la tabla en JS para edición
    const inventario = [
        <?php $result2 = $conn->query("SELECT * FROM inventario ORDER BY nivel, aula_funcional, denominacion");
        while ($r = $result2->fetch_assoc()): ?>
        <?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>,
        <?php endwhile; ?>
    ];

    function editarFila(id) {
        const fila = inventario.find(x => x.id == id);
        if (!fila) return;
        document.getElementById('edit_id').value = fila.id;
        document.getElementById('edit_nivel').value = fila.nivel;
        document.getElementById('edit_aula_funcional').value = fila.aula_funcional;
        document.getElementById('edit_denominacion').value = fila.denominacion;
        document.getElementById('edit_marca').value = fila.marca;
        document.getElementById('edit_modelo').value = fila.modelo;
        document.getElementById('edit_tipo').value = fila.tipo;
        document.getElementById('edit_color').value = fila.color;
        document.getElementById('edit_serie').value = fila.serie;
        document.getElementById('edit_largo').value = fila.largo;
        document.getElementById('edit_ancho').value = fila.ancho;
        document.getElementById('edit_alto').value = fila.alto;
        document.getElementById('edit_documento_alta').value = fila.documento_alta;
        document.getElementById('edit_fecha_compra').value = fila.fecha_compra;
        document.getElementById('edit_numero_documento').value = fila.numero_documento;
        document.getElementById('edit_estado').value = fila.estado;
        document.getElementById('edit_procedencia').value = fila.procedencia;
        document.getElementById('edit_observaciones').value = fila.observaciones;
        document.getElementById('edit_usuario_responsable').value = fila.usuario_responsable;
        document.getElementById('edit_ubicacion').value = fila.ubicacion;
        document.getElementById('edit_fecha_registro').value = fila.fecha_registro;
        document.getElementById('modalEditar').style.display = 'flex';
    }
    function cerrarModal() {
        document.getElementById('modalEditar').style.display = 'none';
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>

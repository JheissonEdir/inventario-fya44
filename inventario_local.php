<?php
// inventario_local.php
// Página local para registrar inventario de aula y exportar a CSV

session_start();

// Inicializar inventario en sesión si no existe
if (!isset($_SESSION['inventario'])) {
    $_SESSION['inventario'] = [];
}

// Procesar formulario para agregar item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar'])) {
    $item = [
        'nivel' => $_POST['nivel'],
        'aula_funcional' => $_POST['aula_funcional'],
        'denominacion' => $_POST['denominacion'],
        'marca' => $_POST['marca'],
        'modelo' => $_POST['modelo'],
        'tipo' => $_POST['tipo'],
        'color' => $_POST['color'],
        'serie' => $_POST['serie'],
        'largo' => $_POST['largo'],
        'ancho' => $_POST['ancho'],
        'alto' => $_POST['alto'],
        'documento_alta' => $_POST['documento_alta'],
        'fecha_compra' => $_POST['fecha_compra'],
        'numero_documento' => $_POST['numero_documento'],
        'estado' => $_POST['estado'],
        'procedencia' => $_POST['procedencia'],
        'observaciones' => $_POST['observaciones'],
        'usuario_responsable' => $_POST['usuario_responsable'],
        'ubicacion' => $_POST['ubicacion'],
        'fecha_registro' => date('Y-m-d'),
    ];
    // Validación para evitar duplicados locales (nivel, aula_funcional, denominacion, serie)
    $duplicado = false;
    foreach ($_SESSION['inventario'] as $row) {
        if (
            $row['nivel'] === $item['nivel'] &&
            $row['aula_funcional'] === $item['aula_funcional'] &&
            $row['denominacion'] === $item['denominacion'] &&
            $row['serie'] === $item['serie']
        ) {
            $duplicado = true;
            break;
        }
    }
    if (!$duplicado) {
        $_SESSION['inventario'][] = $item;
    }
}

// Procesar exportación a CSV
if (isset($_POST['exportar'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventario_aula.csv"');
    $f = fopen('php://output', 'w');
    // Encabezados
    fputcsv($f, array_keys($_SESSION['inventario'][0] ?? [
        'nivel' => '', 'aula_funcional' => '', 'denominacion' => '', 'marca' => '', 'modelo' => '', 'tipo' => '', 'color' => '', 'serie' => '', 'largo' => '', 'ancho' => '', 'alto' => '', 'documento_alta' => '', 'fecha_compra' => '', 'numero_documento' => '', 'estado' => '', 'procedencia' => '', 'observaciones' => '', 'usuario_responsable' => '', 'ubicacion' => '', 'fecha_registro' => ''
    ]));
    foreach ($_SESSION['inventario'] as $row) {
        fputcsv($f, $row);
    }
    fclose($f);
    exit;
}

// Procesar limpiar inventario
if (isset($_POST['limpiar'])) {
    $_SESSION['inventario'] = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario de Aula - Local</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; background: #fff; }
        h2 { color: #b71c1c; text-align: center; margin-bottom: 0; }
        h3 { color: #b71c1c; text-align: center; margin-top: 8px; }
        .subtitulo { text-align: center; color: #b71c1c; font-size: 1.1em; margin-bottom: 18px; }
        form { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 2px 8px #0001; max-width: 600px; margin: 0 auto; }
        form label { display: block; margin-bottom: 8px; font-size: 1em; }
        form input, form select { width: 100%; padding: 7px; margin-top: 2px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; }
        .btn { padding: 10px 18px; margin: 6px 2px; border: none; border-radius: 4px; background: #b71c1c; color: #fff; font-size: 1em; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #d32f2f; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px #0001; }
        th, td { border: 1px solid #e57373; padding: 8px 4px; text-align: left; font-size: 0.97em; }
        th { background: #ffcdd2; color: #b71c1c; }
        @media (max-width: 800px) {
            form, table { max-width: 100%; font-size: 0.98em; }
            th, td { font-size: 0.95em; }
        }
        @media (max-width: 600px) {
            form { padding: 8px; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { margin-bottom: 15px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px #0001; }
            td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50%; min-height: 36px; }
            td:before {
                position: absolute;
                top: 8px; left: 8px; width: 45%;
                white-space: nowrap;
                font-weight: bold;
                color: #b71c1c;
                content: attr(data-label);
            }
        }
        footer { margin-top:40px; text-align:center; color:#b71c1c; font-size:1em; }
        hr { border: 0; border-top: 2px solid #b71c1c; }
    </style>
</head>
<body>
    <h2>Inventario del Colegio Fe y Alegría 44</h2>
    <div class="subtitulo">Inventario 2025</div>
    <form method="post" autocomplete="off">
        <label>Nivel:
            <select name="nivel" required>
                <option value="Inicial">Inicial</option>
                <option value="Primaria">Primaria</option>
                <option value="Secundaria">Secundaria</option>
            </select>
        </label>
        <label>Aula Funcional: <input name="aula_funcional" required></label>
        <label>Denominación: <input name="denominacion" required></label>
        <label>Marca: <input name="marca"></label>
        <label>Modelo: <input name="modelo"></label>
        <label>Tipo:
            <select name="tipo" required>
                <option value="Mobiliario">Mobiliario</option>
                <option value="Equipo">Equipo</option>
                <option value="Material">Material</option>
                <option value="Otro">Otro</option>
            </select>
        </label>
        <label>Color: <input name="color"></label>
        <label>Serie: <input name="serie"></label>
        <label>Largo: <input name="largo" type="number" step="0.01"></label>
        <label>Ancho: <input name="ancho" type="number" step="0.01"></label>
        <label>Alto: <input name="alto" type="number" step="0.01"></label>
        <label>Documento de Alta: <input name="documento_alta"></label>
        <label>Fecha de Compra: <input name="fecha_compra" type="date"></label>
        <label>Número de Documento: <input name="numero_documento"></label>
        <label>Estado:
            <select name="estado" required>
                <option value="Bueno">Bueno</option>
                <option value="Regular">Regular</option>
                <option value="Malo">Malo</option>
            </select>
        </label>
        <label>Procedencia:
            <select name="procedencia" required>
                <option value="UGEL">UGEL</option>
                <option value="Fe y Alegría">Fe y Alegría</option>
                <option value="Jesuitas">Jesuitas</option>
                <option value="Otra">Otra</option>
            </select>
        </label>
        <label>Observaciones: <input name="observaciones"></label>
        <label>Usuario Responsable: <input name="usuario_responsable"></label>
        <label>Ubicación: <input name="ubicacion"></label>
        <button type="submit" name="agregar" class="btn">Agregar</button>
        <button type="submit" name="exportar" class="btn">Exportar a CSV</button>
        <button type="submit" name="limpiar" class="btn" onclick="return confirm('¿Seguro que deseas limpiar el inventario?')">Limpiar Inventario</button>
    </form>
    <h3>Inventario Actual</h3>
        <footer>
            <hr style="margin:18px 0;">
            <b>Sistema realizado por Max System</b><br>
            <span>Inventario del Colegio Fe y Alegría 44 &mdash; 2025</span><br>
            &copy; <?= date('Y') ?> Derechos reservados
        </footer>
    <table>
        <thead>
        <tr>
            <th>Nivel</th><th>Aula</th><th>Denominación</th><th>Marca</th><th>Modelo</th><th>Tipo</th><th>Color</th><th>Serie</th><th>Largo</th><th>Ancho</th><th>Alto</th><th>Doc. Alta</th><th>Fecha Compra</th><th>N° Doc</th><th>Estado</th><th>Procedencia</th><th>Obs.</th><th>Usuario</th><th>Ubicación</th><th>Fecha Registro</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($_SESSION['inventario'] as $row): ?>
        <tr>
            <td data-label="Nivel"><?= htmlspecialchars($row['nivel']) ?></td>
            <td data-label="Aula"><?= htmlspecialchars($row['aula_funcional']) ?></td>
            <td data-label="Denominación"><?= htmlspecialchars($row['denominacion']) ?></td>
            <td data-label="Marca"><?= htmlspecialchars($row['marca']) ?></td>
            <td data-label="Modelo"><?= htmlspecialchars($row['modelo']) ?></td>
            <td data-label="Tipo"><?= htmlspecialchars($row['tipo']) ?></td>
            <td data-label="Color"><?= htmlspecialchars($row['color']) ?></td>
            <td data-label="Serie"><?= htmlspecialchars($row['serie']) ?></td>
            <td data-label="Largo"><?= htmlspecialchars($row['largo']) ?></td>
            <td data-label="Ancho"><?= htmlspecialchars($row['ancho']) ?></td>
            <td data-label="Alto"><?= htmlspecialchars($row['alto']) ?></td>
            <td data-label="Doc. Alta"><?= htmlspecialchars($row['documento_alta']) ?></td>
            <td data-label="Fecha Compra"><?= htmlspecialchars($row['fecha_compra']) ?></td>
            <td data-label="N° Doc"><?= htmlspecialchars($row['numero_documento']) ?></td>
            <td data-label="Estado"><?= htmlspecialchars($row['estado']) ?></td>
            <td data-label="Procedencia"><?= htmlspecialchars($row['procedencia']) ?></td>
            <td data-label="Obs."><?= htmlspecialchars($row['observaciones']) ?></td>
            <td data-label="Usuario"><?= htmlspecialchars($row['usuario_responsable']) ?></td>
            <td data-label="Ubicación"><?= htmlspecialchars($row['ubicacion']) ?></td>
            <td data-label="Fecha Registro"><?= htmlspecialchars($row['fecha_registro']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>

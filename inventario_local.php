<?php
// inventario_local.php
// Página local para registrar inventario de aula y exportar a CSV

session_start();

// Configuración local / sincronización
// Para pruebas locales, usamos la URL local y un token de test.
// Cambiar estos valores antes de publicar en cPanel público.
define('CENTRAL_URL', 'http://localhost/Inventario%20Escolar/inventario_central.php'); // URL local para pruebas

// Cambiar el token de prueba por un sistema dinámico
$syncTokenFile = __DIR__ . '/sync_token.txt';
if (file_exists($syncTokenFile)) {
    define('SYNC_TOKEN', trim(file_get_contents($syncTokenFile)));
} else {
    die('Error: El archivo sync_token.txt no existe.');
}

// Autenticación simple: `users.json` en la misma carpeta. Formato: [{"user":"juan","pass":"clavehash"}, ...]
// Para simplicidad usamos contraseñas en texto plano en este ejemplo; en producción use hashing y permisos fuera de webroot.

// Manejo de login/logout
if (isset($_POST['login']) && !empty($_POST['user'])) {
    $users_file = __DIR__ . '/users.json';
    $users = [];
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true) ?: [];
    }
    $found = false;
    foreach ($users as $u) {
        if ($u['user'] === $_POST['user'] && password_verify($_POST['pass'], $u['pass'])) {
            $_SESSION['user'] = $u['user'];
            $found = true;
            break;
        }
    }
    $login_msg = $found ? 'Sesión iniciada' : 'Usuario/clave inválidos';
}

// Generar token por usuario al iniciar sesión (si no existe)
if (!empty($_SESSION['user'])) {
    $users_file = __DIR__ . '/users.json';
    $users = [];
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true) ?: [];
    }
    $changed = false;
    foreach ($users as &$u) {
        if ($u['user'] === $_SESSION['user']) {
            if (empty($u['token'])) {
                try { $u['token'] = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $u['token'] = md5(uniqid($u['user'], true)); }
                $u['token_created'] = date('c');
                $changed = true;
            }
            // Guardar token en sesión también
            $_SESSION['user_token'] = $u['token'];
            break;
        }
    }
    unset($u);
    if ($changed) file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    session_destroy();
    header('Location: inventario_local.php');
    exit;
}

// Cargar inventario desde archivo JSON por usuario
function carga_inventario_local() {
    if (empty($_SESSION['user'])) return [];
    $file = __DIR__ . '/data_local_' . preg_replace('/[^a-z0-9_\-]/i','_',$_SESSION['user']) . '.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function guarda_inventario_local($arr) {
    if (empty($_SESSION['user'])) return false;
    $file = __DIR__ . '/data_local_' . preg_replace('/[^a-z0-9_\-]/i','_',$_SESSION['user']) . '.json';
    return file_put_contents($file, json_encode($arr, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

// Inicializar inventario en sesión si no existe (mantener compatibilidad UI)
if (!isset($_SESSION['inventario'])) {
    $_SESSION['inventario'] = carga_inventario_local();
}

// Cargar listado de aulas desde `aulas.json` para el select dinámico
$aulas_file = __DIR__ . '/aulas.json';
$aulas = [
    'Inicial' => [],
    'Primaria' => [],
    'Secundaria' => []
];
if (file_exists($aulas_file)) {
    $aulas_json = json_decode(file_get_contents($aulas_file), true);
    if (is_array($aulas_json)) {
        $aulas = $aulas_json + $aulas; // merge conservando claves
    }
}

// Procesar formulario para agregar item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar'])) {
    $item = [
        'nivel' => $_POST['nivel'],
        'aula_funcional' => $_POST['aula_funcional'],
        'cantidad' => isset($_POST['cantidad']) ? intval($_POST['cantidad']) : 1,
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
        guarda_inventario_local($_SESSION['inventario']);
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
    guarda_inventario_local($_SESSION['inventario']);
}

// Enviar a inventario_central.php mediante cURL (multipart/form-data)
// Ahora permitimos enviar aunque el usuario local no haya iniciado sesión: basta con proporcionar el token.
if (isset($_POST['enviar_central'])) {
    // Token global y token del remitente (generado al iniciar sesión)
    $global_token = SYNC_TOKEN;
    $sender_token = $_SESSION['user_token'] ?? '';

    // Generar CSV temporal
        $tmp = tempnam(sys_get_temp_dir(), 'inv');
        $f = fopen($tmp, 'w');
        $enc = array_keys($_SESSION['inventario'][0] ?? [
            'nivel' => '', 'aula_funcional' => '', 'denominacion' => '', 'marca' => '', 'modelo' => '', 'tipo' => '', 'color' => '', 'serie' => '', 'largo' => '', 'ancho' => '', 'alto' => '', 'documento_alta' => '', 'fecha_compra' => '', 'numero_documento' => '', 'estado' => '', 'procedencia' => '', 'observaciones' => '', 'usuario_responsable' => '', 'ubicacion' => '', 'fecha_registro' => ''
        ]);
        fputcsv($f, $enc);
        foreach ($_SESSION['inventario'] as $row) {
            fputcsv($f, $row);
        }
        fclose($f);

        // Preparar POST multipart
        $cfile = new CURLFile($tmp, 'text/csv', 'inventario_aula.csv');
        // Generar sync_id único por envío (usar usuario de sesión si existe, sino 'anon')
        try {
            $base_user = preg_replace('/[^a-z0-9_\-]/i','_', $_SESSION['user'] ?? 'anon');
            $rand = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid();
            $sync_id = $base_user . '_' . $rand;
        } catch (\Throwable $e) {
            $sync_id = preg_replace('/[^a-z0-9_\-]/i','_', $_SESSION['user'] ?? 'anon') . '_' . uniqid();
        }

        $post = [
            'importar' => '1',
            'sync_token' => $global_token,
            'sender_token' => $sender_token,
            'anio' => date('Y'),
            'sync_id' => $sync_id,
            'source_user' => trim($_POST['source_user'] ?? ($_SESSION['user'] ?? '')),
            'csv' => $cfile
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, CENTRAL_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($tmp);
        if ($resp === false || $err) {
            $msg_sync = 'Error al sincronizar: ' . ($err ?: 'Respuesta inválida');
            $toaster_msg = $msg_sync;
            $toaster_ok = false;
        } else {
            // Intentar parsear importados/duplicados desde la respuesta
            $importados = null; $duplicados = null;
            // Respuesta tipo JSON (cuando central devuelve detalles)
            if (strpos($resp, '{') !== false) {
                $j = json_decode($resp, true);
                if (is_array($j)) {
                    if (isset($j['importados'])) $importados = $j['importados'];
                    if (isset($j['duplicados'])) $duplicados = $j['duplicados'];
                }
            }
            // Respuesta tipo texto: "Importados: X. Duplicados: Y."
            if ($importados === null) {
                if (preg_match('/Importados\s*[:\-]?\s*(\d+)/i', $resp, $m)) $importados = intval($m[1]);
                if (preg_match('/Duplicados\s*[:\-]?\s*(\d+)/i', $resp, $m2)) $duplicados = intval($m2[1]);
            }
            // Construir mensaje
            if ($importados !== null) {
                $msg_sync = "Sincronización completada. Importados: $importados." . ($duplicados!==null ? " Duplicados: $duplicados." : '');
                $toaster_msg = $msg_sync;
                $toaster_ok = true;
            } else {
                $resp_short = strlen($resp) > 200 ? substr($resp,0,200).'...' : $resp;
                $msg_sync = 'Sincronización completada. sync_id: ' . htmlspecialchars($sync_id) . '. Respuesta: ' . htmlspecialchars($resp_short);
                $toaster_msg = $msg_sync;
                $toaster_ok = true;
            }
        }
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
    <?php if (empty($_SESSION['user'])): ?>
        <div style="max-width:600px;margin:6px auto;padding:8px;background:#fff;border-radius:8px;text-align:center;">
            <form method="post" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;align-items:center;">
                <input name="user" placeholder="Usuario" style="width:140px;padding:6px;border-radius:6px;border:1px solid #ccc;">
                <input name="pass" placeholder="Clave" type="password" style="width:140px;padding:6px;border-radius:6px;border:1px solid #ccc;">
                <button name="login" class="btn" type="submit">Iniciar sesión</button>
            </form>
            <?php if (!empty($login_msg)): ?><div style="color:#b71c1c;margin-top:8px;font-weight:bold;"><?= htmlspecialchars($login_msg) ?></div><?php endif; ?>
            <div style="margin-top:6px;color:#666;font-size:0.9em;">Si no tiene usuario, cree uno en el archivo <code>users.json</code> en el servidor.</div>
        </div>
    <?php else: ?>
        <div style="max-width:900px;margin:6px auto 12px;padding:8px;background:#fff;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
            <div>Usuario: <b><?= htmlspecialchars($_SESSION['user']) ?></b></div>
            <div style="display:flex;gap:6px;align-items:center;">
                <form method="post" style="display:inline-block;margin:0;align-items:center;">
                    <input type="hidden" name="sender_token" value="<?= htmlspecialchars($_SESSION['user_token'] ?? '') ?>">
                    <button name="enviar_central" class="btn" type="submit">Enviar a inventario central</button>
                </form>
                <a href="?logout=1" class="btn" style="background:#777;padding:8px 12px;text-decoration:none;color:#fff;border-radius:6px;">Cerrar sesión</a>
            </div>
        </div>
        <?php if (!empty($msg_sync)): ?><div id="server_msg" style="max-width:900px;margin:6px auto;padding:8px;background:#e8ffe8;border-radius:8px;color:#006400;"><?= htmlspecialchars($msg_sync) ?></div><?php endif; ?>
        <!-- Toaster container -->
        <div id="toaster" style="position:fixed;right:20px;bottom:20px;min-width:220px;padding:12px;border-radius:8px;background:#323232;color:#fff;display:none;box-shadow:0 4px 16px rgba(0,0,0,0.3);z-index:9999;"></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <label>Nivel:
            <select name="nivel" id="nivel_select" required onchange="populateAulas(this.value)">
                <option value="Inicial" <?= (isset($_POST['nivel']) && $_POST['nivel']==='Inicial') ? 'selected' : '' ?>>Inicial</option>
                <option value="Primaria" <?= (isset($_POST['nivel']) && $_POST['nivel']==='Primaria') ? 'selected' : '' ?>>Primaria</option>
                <option value="Secundaria" <?= (isset($_POST['nivel']) && $_POST['nivel']==='Secundaria') ? 'selected' : '' ?>>Secundaria</option>
            </select>
        </label>
        <label>Aula Funcional:
            <select name="aula_funcional" id="aula_select" required>
                <option value="">-- Seleccione aula --</option>
            </select>
        </label>
        <label>Denominación: <input name="denominacion" required></label>
        <label>Cantidad: <input name="cantidad" type="number" min="1" value="1" required></label>
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
            <th>Nivel</th><th>Aula</th><th>Cantidad</th><th>Denominación</th><th>Marca</th><th>Modelo</th><th>Tipo</th><th>Color</th><th>Serie</th><th>Largo</th><th>Ancho</th><th>Alto</th><th>Doc. Alta</th><th>Fecha Compra</th><th>N° Doc</th><th>Estado</th><th>Procedencia</th><th>Obs.</th><th>Usuario</th><th>Ubicación</th><th>Fecha Registro</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($_SESSION['inventario'] as $row): ?>
        <tr>
            <td data-label="Nivel"><?= htmlspecialchars($row['nivel']) ?></td>
            <td data-label="Aula"><?= htmlspecialchars($row['aula_funcional']) ?></td>
            <td data-label="Cantidad"><?= htmlspecialchars($row['cantidad'] ?? '') ?></td>
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
    <script>
        // Datos de aulas inyectados desde PHP
        var aulas = <?= json_encode($aulas, JSON_UNESCAPED_UNICODE) ?>;
        function populateAulas(nivel) {
            var sel = document.getElementById('aula_select');
            sel.innerHTML = '';
            var def = document.createElement('option');
            def.value = '';
            def.textContent = '-- Seleccione aula --';
            sel.appendChild(def);
            if (!nivel || !aulas[nivel]) return;
            aulas[nivel].forEach(function(a) {
                var opt = document.createElement('option');
                opt.value = a;
                opt.textContent = a;
                sel.appendChild(opt);
            });
        }
        // Inicializar select con el primer nivel mostrado
        (function(){
            var nivelSel = document.getElementById('nivel_select');
            if (nivelSel) {
                populateAulas(nivelSel.value);
                // Si venimos de POST, seleccionar el aula previamente enviada
                var prevAula = <?= json_encode($_POST['aula_funcional'] ?? '') ?>;
                if (prevAula) {
                    var sel = document.getElementById('aula_select');
                    sel.value = prevAula;
                }
            }
        })();
    </script>
</body>
</html>

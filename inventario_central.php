<?php 
// Aumentar memoria temporalmente (por ejemplo 512M)
@ini_set('memory_limit', '512M');
// Entorno: en producción ocultar warnings y notices para evitar mostrar errores al usuario
@ini_set('display_errors', 0);
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
// Descargar plantilla CSV
if (isset($_GET['download_template'])) {
    $headers = ['Nivel','Aula Funcional','Denominación','Marca','Modelo','Tipo','Color','Serie','Largo','Ancho','Alto','Documento de Alta','Fecha Compra','N° Documento','Estado','Procedencia','Observaciones','Usuario Responsable','Ubicación','Fecha Registro','Cantidad'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_inventario.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, $headers);
    // Fila de ejemplo
    fputcsv($out, ['Inicial','3 Años A','Mesa de madera','MADERA','CIRCULAR','Mobiliario','MADERA','', '1.00','0.60','0.75','DOC-001','2025-11-01','0001','Bueno','Fe y Alegría','Buen estado','Juan Pérez','Aula 3','2025-11-25','1']);
    fclose($out);
    exit;
}
// inventario_central.php
// Página para importar CSV y mostrar inventario centralizado

// Usar credenciales seguras para la base de datos
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

// Usar consultas preparadas para prevenir inyecciones SQL
function table_has_column($conn, $table, $column) {
    // Algunas versiones de MySQL no permiten placeholders en SHOW queries.
    // Escapamos los identificadores/valores y ejecutamos la consulta directamente.
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $query = "SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'";
    $res = $conn->query($query);
    return ($res && $res->num_rows > 0);
}

function table_exists($conn, $table) {
    $table = $conn->real_escape_string($table); // Escapar el nombre de la tabla
    $query = "SHOW TABLES LIKE '$table'";
    $res = $conn->query($query);
    return ($res && $res->num_rows > 0);
}

// Safe array combine: devuelve un array asociativo a partir de $keys y $values
// Si hay menos valores que claves, completa con cadenas vacías.
// Si hay más valores que claves, concatena los extras en la última clave separados por ' | '.
function safe_assoc($keys, $values) {
    $kcnt = is_array($keys) ? count($keys) : 0;
    $vcnt = is_array($values) ? count($values) : 0;
    if ($kcnt === 0) return [];
    if ($kcnt === $vcnt) return array_combine($keys, $values);
    if ($vcnt < $kcnt) {
        $pad = array_fill(0, $kcnt - $vcnt, '');
        $values = array_merge($values, $pad);
        return array_combine($keys, $values);
    }
    // $vcnt > $kcnt: fusionar extras en la última clave
    $first = array_slice($values, 0, $kcnt - 1);
    $extra = array_slice($values, $kcnt - 1);
    $last = implode(' | ', $extra);
    $values = array_merge($first, [$last]);
    return array_combine($keys, $values);
}

// Limpiar líneas CSV malformadas con escape de comillas incorrecto
// Ejemplo de problema: "Primaria,""4C"",""ESTANTE"",...
// Solución: quitar comilla inicial/final y reemplazar "" por "
function cleanMalformedCSVLine($line) {
    // Detectar patrón: línea que empieza con comilla y tiene doble comilla interna precedida por coma
    if (preg_match('/^"[^"]*,""/', $line)) {
        // Quitar comilla inicial y final
        $line = preg_replace('/^"/', '', $line);
        $line = preg_replace('/"$/', '', $line);
        // Reemplazar todas las ocurrencias de "" por "
        $line = str_replace('""', '"', $line);
    }
    return $line;
}

// ¿La tabla `inventario` tiene columna `cantidad`?
$has_cantidad = table_has_column($conn, 'inventario', 'cantidad');
// Sesión/admin minimal: permitir AJAX y sincronización remota sin login
session_start();
// Si no es petición AJAX ni sincronización por token, exigir login
$is_ajax = isset($_GET['ajax']);
// Considerar sincronización si se envía `sync_token` y archivo, o si viene un token de remitente
$is_sync = (isset($_POST['sync_token']) || isset($_POST['sender_token'])) && isset($_FILES['csv']) && $_FILES['csv']['error']==0;
if (!$is_ajax && !$is_sync) {
    if (empty($_SESSION['admin'])) {
        header('Location: login_central.php');
        exit;
    }
}

// Inicializar variables de inventario anual
$anios = [];
$inventario_id = null;
$inventario_estado = 'activo';

// Obtener años disponibles y estado (si se acaba de crear un año, recargar lista después de la inserción)
$res_anios = $conn->query("SELECT * FROM inventarios ORDER BY anio DESC");
$anios = [];
while ($row = $res_anios->fetch_assoc()) {
    $anios[] = $row;
}



// Determinar inventario activo según parámetro GET
$inventario_id = null;
$inventario_estado = 'activo';
if (isset($_GET['anio'])) {
    foreach ($anios as $inv) {
        if ($inv['anio'] == $_GET['anio']) {
            $inventario_id = $inv['id'];
            $inventario_estado = $inv['estado'];
            break;
        }
    }
}
// Si no se encontró o no hay parámetro, usar el más reciente
if ($inventario_id === null && count($anios) > 0) {
    $inventario_id = $anios[0]['id'];
    $inventario_estado = $anios[0]['estado'];
}

// Crear nuevo año (copia del anterior) con feedback robusto
if (isset($_POST['nuevo_anio']) && isset($_POST['anio_nuevo'])) {
    $anio_nuevo = intval($_POST['anio_nuevo']);
    // Buscar inventario actual
    $inventario_id_actual = $inventario_id;
    $res_existente = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_nuevo");
    if ($res_existente && $res_existente->num_rows) {
        $mensaje = "<b style='color:#b71c1c;'>El año $anio_nuevo ya existe. No se realizó ninguna copia.</b>";
    } else {
        $conn->query("INSERT INTO inventarios (anio, estado) VALUES ($anio_nuevo, 'activo')");
        $nuevo_id = $conn->insert_id;
        $copiados = 0;
        $res_bienes = $conn->query("SELECT * FROM inventario WHERE inventario_id=$inventario_id_actual");
        if ($res_bienes && $res_bienes->num_rows > 0) {
            $conn->query("INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, inventario_id) SELECT nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, $nuevo_id FROM inventario WHERE inventario_id=$inventario_id_actual");
            $copiados = $res_bienes->num_rows;
        }
        // Redirigir para mostrar el año nuevo y el mensaje
        header("Location: inventario_central.php?anio=$anio_nuevo&copiados=$copiados");
        exit;
    }
}

// Cerrar año
if (isset($_POST['cerrar_anio']) && $inventario_id) {
    $conn->query("UPDATE inventarios SET estado='cerrado' WHERE id=$inventario_id");
    header("Location: inventario_central.php?anio=".$_GET['anio']);
    exit;
}

// --- AJAX endpoint para inventario dinámico ---
if (isset($_GET['ajax'])) {
    // Parámetros de filtro
    $where = [];
    if (!empty($_GET['nivel'])) {
        $nivel = $conn->real_escape_string($_GET['nivel']);
        $where[] = "nivel='$nivel'";
    }
    if (!empty($_GET['aula'])) {
        $aula = $conn->real_escape_string($_GET['aula']);
        $where[] = "aula_funcional LIKE '%$aula%'";
    }
    if (!empty($_GET['denominacion'])) {
        $denom = $conn->real_escape_string($_GET['denominacion']);
        $where[] = "denominacion LIKE '%$denom%'";
    }
    if (!empty($_GET['inventario_id'])) {
        $where[] = "inventario_id=".intval($_GET['inventario_id']);
    } else {
        // si no se envía inventario_id, usar inventario activo
        if ($inventario_id === null) {
            // No hay inventario, devolver vacío
            header('Content-Type: application/json');
            echo json_encode(['data'=>[],'total'=>0,'page'=>1,'per_page'=>100,'message'=>'No hay inventarios creados'], JSON_UNESCAPED_UNICODE);
            $conn->close();
            exit;
        }
        $where[] = "inventario_id=".intval($inventario_id);
    }

    // Si piden un id específico, devolver array (compatibilidad con editarFila)
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $sql = "SELECT * FROM inventario WHERE id=$id LIMIT 1";
        $res = $conn->query($sql);
        $datos = [];
        if ($res && $row = $res->fetch_assoc()) $datos[] = $row;
        header('Content-Type: application/json');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $baseSql = "FROM inventario" . (count($where) ? " WHERE " . implode(' AND ', $where) : "") ;
    // Paginación
    $page = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(10,min(500,intval($_GET['per_page']))) : 100;
    $offset = ($page - 1) * $per_page;

    // Total
    $countRes = $conn->query("SELECT COUNT(*) AS cnt " . $baseSql);
    $total = ($countRes && ($r = $countRes->fetch_assoc())) ? intval($r['cnt']) : 0;

    $sql = "SELECT * " . $baseSql . " ORDER BY nivel ASC, aula_funcional ASC, denominacion ASC LIMIT $per_page OFFSET $offset";
    $res = $conn->query($sql);
    
    if (!$res) {
        // Error en la consulta
        header('Content-Type: application/json');
        echo json_encode(['data'=>[],'total'=>0,'page'=>1,'per_page'=>100,'error'=>$conn->error], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }
    
    $datos = [];
    while ($row = $res->fetch_assoc()) {
        $datos[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode(['data'=>$datos,'total'=>$total,'page'=>$page,'per_page'=>$per_page], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$mensaje = '';

// Endpoint administrador: listar sync_log.json (historial de sincronizaciones)
if (isset($_GET['sync_log'])) {
    // A esta ruta solo debe acceder un admin con sesión (el control de sesión ya está arriba)
    $sync_log_file = __DIR__ . '/sync_log.json';
    $entries = [];
    if (file_exists($sync_log_file)) {
        $entries = json_decode(file_get_contents($sync_log_file), true) ?: [];
    }
    // Filtros opcionales
    $filter_user = trim($_GET['source_user'] ?? '');
    $from = trim($_GET['from'] ?? '');
    $to = trim($_GET['to'] ?? '');
    // Aplicar filtros
    $filtered = array_filter($entries, function($e) use ($filter_user, $from, $to) {
        if ($filter_user !== '' && (!isset($e['source_user']) || stripos($e['source_user'], $filter_user) === false)) return false;
        $ts = isset($e['timestamp']) ? strtotime($e['timestamp']) : false;
        if ($from !== '' && $ts !== false && $ts < strtotime($from)) return false;
        if ($to !== '' && $ts !== false && $ts > strtotime($to)) return false;
        return true;
    });
    // API JSON
    if (isset($_GET['format']) && $_GET['format']==='json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_values($filtered), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        $conn->close(); exit;
    }
    // CSV download
    if (isset($_GET['format']) && $_GET['format']==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sync_log.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['timestamp','sync_id','hash','anio','inventario_id','importados','duplicados','source_user','uploader_ip']);
        foreach ($filtered as $e) {
            fputcsv($out, [
                $e['timestamp'] ?? '', $e['sync_id'] ?? '', $e['hash'] ?? '', $e['anio'] ?? '', $e['inventario_id'] ?? '', $e['importados'] ?? '', $e['duplicados'] ?? '', $e['source_user'] ?? '', $e['uploader_ip'] ?? ''
            ]);
        }
        fclose($out);
        $conn->close(); exit;
    }
    // Render simple HTML con la tabla y filtros
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Historial de Sincronizaciones</title>
        <style>body{font-family:Arial;margin:18px;background:#f7f7f7}table{border-collapse:collapse;width:100%;background:#fff}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f1f1f1;color:#333}h2{color:#b71c1c}a.btn{display:inline-block;padding:8px 12px;background:#b71c1c;color:#fff;border-radius:6px;text-decoration:none}form.filters{margin-bottom:12px;background:#fff;padding:10px;border-radius:8px;display:flex;gap:8px;align-items:center}</style>
    </head>
    <body>
        <h2>Historial de Sincronizaciones</h2>
        <p><a class="btn" href="inventario_central.php">Volver al panel</a></p>
        <form method="get" class="filters">
            <input type="hidden" name="sync_log" value="1">
            <label>Usuario origen: <input name="source_user" value="<?= htmlspecialchars($filter_user) ?>"></label>
            <label>Desde: <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
            <label>Hasta: <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn" href="inventario_central.php?sync_log=1&format=csv&source_user=<?= urlencode($filter_user) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Descargar CSV</a>
            <a class="btn" href="inventario_central.php?sync_log=1&format=json&source_user=<?= urlencode($filter_user) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">JSON</a>
        </form>
        <table>
            <thead>
                <tr><th>Fecha</th><th>Sync ID</th><th>Hash</th><th>Año</th><th>Inventario ID</th><th>Importados</th><th>Duplicados</th><th>Origen</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php if (!$filtered): ?>
                <tr><td colspan="9" style="text-align:center;padding:14px;color:#666">No hay registros de sincronización.</td></tr>
            <?php else: foreach ($filtered as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['timestamp'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['sync_id'] ?? '') ?></td>
                    <td style="font-family:monospace;word-break:break-all;max-width:240px"><?= htmlspecialchars($e['hash'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['anio'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['inventario_id'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['importados'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['duplicados'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['source_user'] ?? '') ?></td>
                    <td><?= htmlspecialchars($e['uploader_ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

// --- Gestión de remitentes (senders) ---
$senders_file = __DIR__ . '/senders.json';
if (!file_exists($senders_file)) file_put_contents($senders_file, json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$senders = json_decode(file_get_contents($senders_file), true) ?: [];

// Procesar acciones CRUD de remitentes (solo admin con sesión)
if (!empty($_SESSION['admin'])) {
    if (isset($_POST['create_sender'])) {
        $user = preg_replace('/[^a-z0-9_\-]/i','_', trim($_POST['sender_user'] ?? ''));
        if ($user === '') $mensaje = 'Nombre de usuario inválido.';
        else {
            // Generar token único
            $token = bin2hex(random_bytes(16));
            // Evitar duplicados de nombre
            $exists = false;
            foreach ($senders as $s) if (isset($s['user']) && $s['user'] === $user) { $exists = true; break; }
            if ($exists) { $mensaje = 'Ya existe un remitente con ese nombre.'; }
            else {
                $senders[] = ['user'=>$user,'token'=>$token,'created'=>date('c')];
                file_put_contents($senders_file, json_encode($senders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $mensaje = "Remitente creado: $user";
            }
        }
    }
    if (isset($_POST['delete_sender'])) {
        $user = trim($_POST['delete_sender'] ?? '');
        foreach ($senders as $k=>$s) {
            if (isset($s['user']) && $s['user'] === $user) { unset($senders[$k]); $deleted = true; break; }
        }
        $senders = array_values($senders);
        file_put_contents($senders_file, json_encode($senders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $mensaje = isset($deleted) ? "Remitente eliminado: $user" : "Remitente no encontrado: $user";
    }
    if (isset($_POST['regen_sender'])) {
        $user = trim($_POST['regen_sender'] ?? '');
        foreach ($senders as $k=>$s) {
            if (isset($s['user']) && $s['user'] === $user) {
                $senders[$k]['token'] = bin2hex(random_bytes(16));
                $senders[$k]['updated'] = date('c');
                file_put_contents($senders_file, json_encode($senders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $mensaje = "Token regenerado para: $user";
                break;
            }
        }
    }
    if (isset($_POST['update_sender'])) {
        $old = trim($_POST['old_sender'] ?? '');
        $new = preg_replace('/[^a-z0-9_\-]/i','_', trim($_POST['new_sender'] ?? ''));
        if ($new === '') $mensaje = 'Nombre nuevo inválido.';
        else {
            $ok = false;
            foreach ($senders as $k=>$s) {
                if (isset($s['user']) && $s['user'] === $old) {
                    $senders[$k]['user'] = $new;
                    $senders[$k]['updated'] = date('c');
                    $ok = true; break;
                }
            }
            file_put_contents($senders_file, json_encode($senders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $mensaje = $ok ? "Remitente renombrado: $old -> $new" : "Remitente no encontrado: $old";
        }
    }
}

// Página de gestión: listar / crear / eliminar / regenerar tokens
if (!empty($_SESSION['admin']) && isset($_GET['manage_senders'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Gestión de Remitentes</title>
        <style>body{font-family:Arial;margin:18px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f1f1f1;color:#333}.btn{background:#b71c1c;color:#fff;padding:8px 12px;text-decoration:none;border-radius:6px}</style>
    </head>
    <body>
        <h2>Remitentes autorizados para envío</h2>
        <p><a class="btn" href="inventario_central.php">Volver al panel</a></p>
        <?php if (!empty($mensaje)): ?><div style="padding:8px;margin:8px 0;background:#e8ffe8;border:1px solid #a6e6a6"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <h3>Crear remitente</h3>
        <form method="post" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;">
            <input name="sender_user" placeholder="usuario_remitente" required>
            <button type="submit" name="create_sender" class="btn">Crear y generar token</button>
        </form>
        <h3>Lista de remitentes</h3>
        <table>
            <thead><tr><th>Usuario</th><th>Token (oculto parcial)</th><th>Creado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (empty($senders)): ?>
                <tr><td colspan="4">No hay remitentes creados.</td></tr>
            <?php else: foreach ($senders as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['user'] ?? '') ?></td>
                    <td style="font-family:monospace"><?= htmlspecialchars(substr($s['token'] ?? '',0,8)) ?>…<?= htmlspecialchars(substr($s['token'] ?? '',-6)) ?></td>
                    <td><?= htmlspecialchars($s['created'] ?? '') ?></td>
                    <td>
                        <form method="post" style="display:inline-block;margin-right:6px;"><input type="hidden" name="delete_sender" value="<?= htmlspecialchars($s['user']) ?>"><button class="btn" onclick="return confirm('Eliminar remitente?')">Eliminar</button></form>
                        <form method="post" style="display:inline-block;margin-right:6px;"><input type="hidden" name="regen_sender" value="<?= htmlspecialchars($s['user']) ?>"><button class="btn">Regenerar token</button></form>
                        <form method="post" style="display:inline-block;"><input type="hidden" name="old_sender" value="<?= htmlspecialchars($s['user']) ?>"><input name="new_sender" placeholder="nuevo nombre"><button name="update_sender" class="btn">Renombrar</button></form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p style="margin-top:12px;color:#666">Nota: comparte el token (mostrado parcialmente aquí) con la persona que usará `inventario_local.php`. Ellos deben enviar ese token en el campo `sync_token` al hacer la sincronización.</p>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

// --- Gestión de aulas (JSON) ---
$aulas_file = __DIR__ . '/aulas.json';
if (!file_exists($aulas_file)) {
    file_put_contents($aulas_file, json_encode(["Inicial"=>[],"Primaria"=>[],"Secundaria"=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
$aulas = json_decode(file_get_contents($aulas_file), true);

// Procesar gestión de aulas
if (isset($_POST['gestionar_aulas'])) {
    $nivel = $_POST['nivel_aula'];
    $nueva_aula = trim($_POST['nueva_aula']);
    if ($nivel && $nueva_aula && !in_array($nueva_aula, $aulas[$nivel])) {
        $aulas[$nivel][] = $nueva_aula;
        file_put_contents($aulas_file, json_encode($aulas, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $mensaje = "Aula agregada correctamente.";
    }
}
if (isset($_POST['eliminar_aula'])) {
    $nivel = $_POST['nivel_aula'];
    $aula = $_POST['aula_eliminar'];
    if (($key = array_search($aula, $aulas[$nivel])) !== false) {
        unset($aulas[$nivel][$key]);
        $aulas[$nivel] = array_values($aulas[$nivel]);
        file_put_contents($aulas_file, json_encode($aulas, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $mensaje = "Aula eliminada correctamente.";
    }
}

// Procesar eliminación
// Procesar eliminación individual o por lote (legacy); preferir endpoints AJAX move_to_trash/undo_trash
if ($inventario_estado=='activo') {
    if (isset($_POST['eliminar_ids']) && is_array($_POST['eliminar_ids'])) {
        $ids = array_map('intval', $_POST['eliminar_ids']);
        $ids = array_filter($ids, function($v){ return $v>0; });
        if (!empty($ids)) {
            $ids_list = implode(',', $ids);
            // Mover a inventario_trash si existe, sino eliminar directamente
            if (table_exists($conn,'inventario_trash')) {
                $conn->query("INSERT INTO inventario_trash SELECT *, NOW() as deleted_at FROM inventario WHERE id IN ($ids_list) AND inventario_id=$inventario_id");
            }
            $conn->query("DELETE FROM inventario WHERE id IN ($ids_list) AND inventario_id=$inventario_id");
            $mensaje = 'Eliminados ' . $conn->affected_rows . ' registros seleccionados.';
        }
    } elseif (isset($_POST['eliminar']) && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        if (table_exists($conn,'inventario_trash')) {
            $conn->query("INSERT INTO inventario_trash SELECT *, NOW() as deleted_at FROM inventario WHERE id=$id AND inventario_id=$inventario_id");
        }
        $conn->query("DELETE FROM inventario WHERE id=$id AND inventario_id=$inventario_id");
        $mensaje = 'Registro eliminado.';
    }
}

// Procesar actualización
if (isset($_POST['actualizar']) && isset($_POST['id']) && $inventario_estado=='activo') {
    $id = intval($_POST['id']);
    $campos = [
        'nivel', 'aula_funcional', 'denominacion', 'marca', 'modelo', 'tipo', 'color', 'serie',
        'largo', 'ancho', 'alto', 'documento_alta', 'fecha_compra', 'numero_documento', 'estado',
        'procedencia', 'observaciones', 'usuario_responsable', 'ubicacion', 'fecha_registro'
    ];
    // Incluir cantidad en campos si la columna existe
    if ($has_cantidad) $campos[] = 'cantidad';
    $set = [];
    foreach ($campos as $campo) {
        $valor = $conn->real_escape_string($_POST[$campo] ?? '');
        $set[] = "$campo='" . $valor . "'";
    }
    $sql = "UPDATE inventario SET ".implode(',', $set)." WHERE id=$id AND inventario_id=$inventario_id";
    $conn->query($sql);
}

// --- Endpoint de sincronización remota (desde `inventario_local.php`) ---
// Requiere un token válido: bien el global en `sync_token.txt` o un token individual generado en `senders.json`.
if ((isset($_POST['sync_token']) || isset($_POST['sender_token'])) && isset($_FILES['csv']) && $_FILES['csv']['error'] == 0) {
    $sent_token = trim($_POST['sync_token'] ?? $_POST['sender_token'] ?? '');
    $sync_file = __DIR__ . '/sync_token.txt';
    $valid = false;
    $matched_sender = null;
    // Validar token global
    if (file_exists($sync_file)) {
        $expected = trim(file_get_contents($sync_file));
        if ($expected !== '' && $expected === $sent_token) $valid = true;
    }
    // Validar token por remitente (senders.json)
    $senders_file = __DIR__ . '/senders.json';
    if (!$valid && file_exists($senders_file)) {
        $senders = json_decode(file_get_contents($senders_file), true) ?: [];
        foreach ($senders as $s) {
            if (!empty($s['token']) && $s['token'] === $sent_token) {
                $valid = true;
                $matched_sender = $s;
                break;
            }
        }
    }
    if (!$valid) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Sync token inválido o no configurado.';
        $conn->close();
        exit;
    }

    // Si se validó con el token global y el cliente envió un `sender_token` y `source_user`, registrar remitente automáticamente
    $used_global = false;
    if (file_exists($sync_file)) {
        $expected = trim(file_get_contents($sync_file));
        if ($expected !== '' && isset($_POST['sync_token']) && trim($_POST['sync_token']) === $expected) {
            $used_global = true;
        }
    }
    if ($used_global && !empty($_POST['sender_token']) && !empty($_POST['source_user'])) {
        $senders_file = __DIR__ . '/senders.json';
        $senders = file_exists($senders_file) ? (json_decode(file_get_contents($senders_file), true) ?: []) : [];
        $found_sender = false;
        foreach ($senders as $s) {
            if (!empty($s['token']) && $s['token'] === trim($_POST['sender_token'])) { $found_sender = true; break; }
        }
        if (!$found_sender) {
            $new = ['user' => trim($_POST['source_user']), 'token' => trim($_POST['sender_token']), 'created' => date('c')];
            $senders[] = $new;
            file_put_contents($senders_file, json_encode($senders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $matched_sender = $new;
        }
    }

    // Si el remitente fue identificado por token, usar su nombre como source_user si no viene otro
    if ($matched_sender && empty($_POST['source_user'])) {
        $_POST['source_user'] = $matched_sender['user'] ?? '';
    }

    // Determinar inventario destino: preferir 'anio', crear año si no existe
    $inventario_id_sync = null;
    if (!empty($_POST['anio'])) {
        $anio_post = intval($_POST['anio']);
        $res_inv = $conn->query("SELECT id FROM inventarios WHERE anio=$anio_post LIMIT 1");
        if ($res_inv && $res_inv->num_rows > 0) {
            $r = $res_inv->fetch_assoc();
            $inventario_id_sync = $r['id'];
        } else {
            $conn->query("INSERT INTO inventarios (anio, estado) VALUES ($anio_post, 'activo')");
            $inventario_id_sync = $conn->insert_id;
        }
    } elseif (!empty($_POST['inventario_id'])) {
        $inventario_id_sync = intval($_POST['inventario_id']);
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo 'Falta parámetro inventario (anio o inventario_id).';
        $conn->close();
        exit;
    }
    // Procesar CSV de importación (misma lógica que importación por web)
    $archivo = $_FILES['csv']['tmp_name'];

    // Evitar procesar dos veces el mismo archivo: usar hash del archivo o un sync_id opcional enviado por el cliente.
    $sync_log_file = __DIR__ . '/sync_log.json';
    if (!file_exists($sync_log_file)) file_put_contents($sync_log_file, json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $sync_log = json_decode(file_get_contents($sync_log_file), true) ?: [];
    $file_hash = @md5_file($archivo) ?: null;
    $sync_id = isset($_POST['sync_id']) ? trim($_POST['sync_id']) : '';
    // Buscar en el log si ya existe un registro con el mismo hash o sync_id
    foreach ($sync_log as $entry) {
        if ($file_hash && !empty($entry['hash']) && $entry['hash'] === $file_hash) {
            header('HTTP/1.1 200 OK');
            echo 'Archivo ya procesado anteriormente. Detalles: ' . json_encode($entry);
            $conn->close();
            exit;
        }
        if ($sync_id !== '' && !empty($entry['sync_id']) && $entry['sync_id'] === $sync_id) {
            header('HTTP/1.1 200 OK');
            echo 'Sync_id ya procesado anteriormente. Detalles: ' . json_encode($entry);
            $conn->close();
            exit;
        }
    }

    $handle = fopen($archivo, 'r');
    if ($handle) {
        // Leer cabecera y limpiar formato malformado
        $rawHeader = fgets($handle);
        if ($rawHeader !== false) {
            $rawHeader = cleanMalformedCSVLine($rawHeader);
            $encabezados = str_getcsv($rawHeader);
        } else {
            $encabezados = [];
        }
        // Normalizar encabezados: minusculas, sin espacios, sin acentos, mapear nombres comunes
        $normalized = [];
        foreach ($encabezados as $h) {
            $k = mb_strtolower(trim($h));
            // quitar acentos básicos
            $k = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $k);
            $k = preg_replace('/[^a-z0-9_]/u', '_', $k);
            // mapeos comunes
            if ($k === 'aula') $k = 'aula_funcional';
            if ($k === 'denominacion' || $k === 'denominaci_n') $k = 'denominacion';
            if ($k === 'usuario' || $k === 'usuario_responsable') $k = 'usuario_responsable';
            $normalized[] = $k;
        }
        $encabezados = $normalized;
        // Validación previa: verificar que la cabecera contenga columnas requeridas
        $required_headers = ['aula_funcional','nivel','denominacion'];
        $missing_headers = array_values(array_diff($required_headers, $encabezados));
        if (!empty($missing_headers)) {
            $msg = 'Faltan encabezados obligatorios: ' . implode(', ', $missing_headers);
            $is_preview_mode = !empty($_POST['dry_run']) && ($_POST['dry_run'] === '1' || strtolower($_POST['dry_run']) === 'true');
            if ($is_preview_mode) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'mode' => 'dry-run',
                    'error' => 'missing_headers',
                    'missing_headers' => $missing_headers,
                    'message' => $msg
                ], JSON_UNESCAPED_UNICODE);
                fclose($handle);
                $conn->close();
                exit;
            } else {
                header('HTTP/1.1 400 Bad Request');
                echo "Error: $msg\n";
                fclose($handle);
                $conn->close();
                exit;
            }
        }
        // Validación previa: verificar que todas las filas tengan el mismo número
        // de columnas que la cabecera para evitar errores en array_combine.
        $headerCount = count($encabezados);
        $importados = 0;
        $duplicados = 0;
        $would_import = 0;
        $total_rows = 0;
        $sample_rows = [];
        $invalid_rows = 0;
        // Guardar posición actual del puntero (después de leer la cabecera)
        $pos_after_header = ftell($handle);
        // Hacer un primer barrido rápido para detectar filas con columnas faltantes/extras
        $bad_samples = [];
        while (($rawLine = fgets($handle)) !== false) {
            $rawLine = cleanMalformedCSVLine($rawLine);
            $peek = str_getcsv($rawLine);
            $total_rows++;
            $cnt = is_array($peek) ? count($peek) : 0;
            if ($cnt !== $headerCount) {
                $invalid_rows++;
                if (count($bad_samples) < 10) {
                    $bad_samples[] = ['line'=> $total_rows + 1, 'columns_found'=>$cnt, 'sample'=> $peek];
                }
                continue;
            }
            // Si la fila tiene el número correcto de columnas, verificar valores obligatorios no vacíos
            $tmp_assoc = safe_assoc($encabezados, $peek);
            foreach ($required_headers as $rh) {
                if (!isset($tmp_assoc[$rh]) || trim((string)$tmp_assoc[$rh]) === '') {
                    $invalid_rows++;
                    if (count($bad_samples) < 10) {
                        $bad_samples[] = ['line'=> $total_rows + 1, 'columns_found'=>$cnt, 'sample'=> $peek, 'missing_field' => $rh];
                    }
                    break;
                }
            }
        }
        // Si se encontraron filas inválidas: en modo dry-run devolvemos JSON con detalles
        $is_preview_mode = !empty($_POST['dry_run']) && ($_POST['dry_run'] === '1' || strtolower($_POST['dry_run']) === 'true');
        if ($invalid_rows > 0) {
            // guardar copia del archivo inválido para auditoría/descarga
            $backup_dir = __DIR__ . '/backups/import_errors';
            if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
            $backup_name = 'invalid_' . date('Ymd_His') . '_' . substr(md5(uniqid()),0,8) . '.csv';
            @copy($archivo, $backup_dir . '/' . $backup_name);
            $backup_url = 'backups/import_errors/' . $backup_name;
            if ($is_preview_mode) {
                // devolver un JSON con resumen y ejemplos para que la UI lo muestre
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'mode' => 'dry-run',
                    'total_rows' => $total_rows,
                    'rows_invalid' => $invalid_rows,
                    'rows_valid' => max(0, $total_rows - $invalid_rows),
                    'bad_samples' => $bad_samples,
                    'headers' => $encabezados,
                    'autofix_available' => true,
                    'db_has_cantidad' => $has_cantidad,
                    'backup_url' => $backup_url,
                    'message' => "Se detectaron filas con columnas inconsistentes. Revisa las muestras proporcionadas."
                ], JSON_UNESCAPED_UNICODE);
                fclose($handle);
                $conn->close();
                exit;
            } else {
                // Comportamiento estricto en import real: abortar con mensaje textual
                header('HTTP/1.1 400 Bad Request');
                echo "Error: El archivo CSV parece tener filas con diferente número de columnas que la cabecera.\n";
                echo "Columnas esperadas: $headerCount. Filas analizadas: $total_rows. Filas inválidas: $invalid_rows.\n";
                echo "Ejemplos (línea, columnas encontradas):\n";
                foreach ($bad_samples as $s) {
                    $line = $s['line'];
                    $cols = $s['columns_found'];
                    echo "- Línea $line: $cols columnas\n";
                }
                echo "\nAsegúrate de que los valores que contienen comas estén entre comillas y que el archivo tenga la misma estructura en todas las filas.\n";
                fclose($handle);
                $conn->close();
                exit;
            }
        }
        // Volver al inicio de los datos para procesar normalmente
        fseek($handle, $pos_after_header);
        $duplicados = 0;
        $would_import = 0; // para dry-run
        $total_rows = 0;
        $sample_rows = [];
        $by_aula = [];
        $by_nivel = [];
        $by_marca = [];
        $by_estado = [];
        $invalid_rows = 0;
        $processing_start = microtime(true);
        $dry_run = !empty($_POST['dry_run']) && ($_POST['dry_run'] === '1' || strtolower($_POST['dry_run']) === 'true');
        while (($rawLine = fgets($handle)) !== false) {
            $rawLine = cleanMalformedCSVLine($rawLine);
            $data = str_getcsv($rawLine);
            $total_rows++;
            $tmp = safe_assoc($encabezados, $data);
            // Asegurar que todas las claves esperadas existen
            $expected = ['nivel','aula_funcional','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro','cantidad'];
            $row = [];
            foreach ($expected as $k) { $row[$k] = isset($tmp[$k]) ? $tmp[$k] : ''; }
            
            // DEBUG: Log para rastrear valores de aula_funcional (bloque SYNC)
            if (!$dry_run && ($total_rows <= 3 || in_array($row['aula_funcional'], ['18', '19', 'Aula 18', 'Aula 19']))) {
                $log_entry = "[SYNC] Row $total_rows: aula_funcional='{$row['aula_funcional']}', nivel='{$row['nivel']}', denominacion='{$row['denominacion']}'\n";
                $log_entry .= "  tmp array keys: " . implode(', ', array_keys($tmp)) . "\n";
                $log_entry .= "  CSV data: " . json_encode($data) . "\n";
                $log_entry .= "  Headers: " . json_encode($encabezados) . "\n\n";
                @file_put_contents(__DIR__ . '/debug_import_aula.log', $log_entry, FILE_APPEND);
            }
            
            $nivel = $conn->real_escape_string($row['nivel']);
            $aula = $conn->real_escape_string($row['aula_funcional']);
            $denom = $conn->real_escape_string($row['denominacion']);
            $serie = $conn->real_escape_string($row['serie']);
            $cantidad = isset($row['cantidad']) ? intval($row['cantidad']) : 1;
            // Si la tabla no tiene columna 'cantidad', fusionarla en observaciones para conservar dato
            if (!$has_cantidad) {
                $row['observaciones'] = trim(($row['observaciones'] ?? '') . ' (cantidad: ' . $cantidad . ')');
            }
            $sql_check = "SELECT id FROM inventario WHERE nivel='$nivel' AND aula_funcional='$aula' AND denominacion='$denom' AND serie='$serie' AND inventario_id=$inventario_id_sync LIMIT 1";
            $res = $conn->query($sql_check);
            if ($res && $res->num_rows > 0) {
                $duplicados++;
                // contar por agrupaciones
                if ($aula) $by_aula[$aula] = ($by_aula[$aula] ?? 0) + 1;
                if ($nivel) $by_nivel[$nivel] = ($by_nivel[$nivel] ?? 0) + 1;
                if (!empty($row['marca'])) $by_marca[$row['marca']] = ($by_marca[$row['marca']] ?? 0) + 1;
                if (!empty($row['estado'])) $by_estado[$row['estado']] = ($by_estado[$row['estado']] ?? 0) + 1;
                if (count($sample_rows) < 10) $sample_rows[] = ['row'=>$row,'status'=>'duplicate'];
                continue;
            }
            // Si estamos en dry-run, no escribimos en BD; contamos las que se insertarían
            if ($dry_run) {
                $would_import++;
                if ($aula) $by_aula[$aula] = ($by_aula[$aula] ?? 0) + 1;
                if ($nivel) $by_nivel[$nivel] = ($by_nivel[$nivel] ?? 0) + 1;
                if (!empty($row['marca'])) $by_marca[$row['marca']] = ($by_marca[$row['marca']] ?? 0) + 1;
                if (!empty($row['estado'])) $by_estado[$row['estado']] = ($by_estado[$row['estado']] ?? 0) + 1;
                if (count($sample_rows) < 10) $sample_rows[] = ['row'=>$row,'status'=>'would_import'];
                continue;
            }
            $largo = (isset($row['largo']) && is_numeric($row['largo'])) ? (float)$row['largo'] : 0.0;
            $ancho = (isset($row['ancho']) && is_numeric($row['ancho'])) ? (float)$row['ancho'] : 0.0;
            $alto  = (isset($row['alto'])  && is_numeric($row['alto']))  ? (float)$row['alto']  : 0.0;
            $cantidad_val = intval($cantidad);
            if ($has_cantidad) {
                $sql = "INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, cantidad, inventario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                // Preparar variables para bind_param (deben pasarse por referencia)
                $p_nivel = $row['nivel'];
                $p_aula = $row['aula_funcional'];
                $p_denominacion = $row['denominacion'];
                $p_marca = $row['marca'];
                $p_modelo = $row['modelo'];
                $p_tipo = $row['tipo'];
                $p_color = $row['color'];
                $p_serie = $row['serie'];
                $p_largo = (string)$largo;
                $p_ancho = (string)$ancho;
                $p_alto = (string)$alto;
                $p_doc_alta = $row['documento_alta'];
                $p_fecha_compra = $row['fecha_compra'];
                $p_num_doc = $row['numero_documento'];
                $p_estado = $row['estado'];
                $p_procedencia = $row['procedencia'];
                $p_observaciones = $row['observaciones'];
                $p_usuario_responsable = $row['usuario_responsable'];
                $p_ubicacion = $row['ubicacion'];
                $raw_fecha_registro = trim((string)($row['fecha_registro'] ?? ''));
                if ($raw_fecha_registro === '') {
                    $p_fecha_registro = date('Y-m-d');
                } else {
                    if (strpos($raw_fecha_registro, '/') !== false) {
                        $parts = explode('/', $raw_fecha_registro);
                        if (count($parts) === 3) {
                            $p_fecha_registro = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                        } else {
                            $ts = strtotime($raw_fecha_registro);
                            $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                        }
                    } else {
                        $ts = strtotime($raw_fecha_registro);
                        $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                    }
                }
                $p_cantidad = (string)$cantidad_val;
                $p_inventario_id = (string)$inventario_id_sync;
                $stmt->bind_param('ssssssssssssssssssssss',
                    $p_nivel,
                    $p_aula,
                    $p_denominacion,
                    $p_marca,
                    $p_modelo,
                    $p_tipo,
                    $p_color,
                    $p_serie,
                    $p_largo,
                    $p_ancho,
                    $p_alto,
                    $p_doc_alta,
                    $p_fecha_compra,
                    $p_num_doc,
                    $p_estado,
                    $p_procedencia,
                    $p_observaciones,
                    $p_usuario_responsable,
                    $p_ubicacion,
                    $p_fecha_registro,
                    $p_cantidad,
                    $p_inventario_id
                );
            } else {
                $sql = "INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, inventario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $p_nivel = $row['nivel'];
                $p_aula = $row['aula_funcional'];
                $p_denominacion = $row['denominacion'];
                $p_marca = $row['marca'];
                $p_modelo = $row['modelo'];
                $p_tipo = $row['tipo'];
                $p_color = $row['color'];
                $p_serie = $row['serie'];
                $p_largo = (string)$largo;
                $p_ancho = (string)$ancho;
                $p_alto = (string)$alto;
                $p_doc_alta = $row['documento_alta'];
                $p_fecha_compra = $row['fecha_compra'];
                $p_num_doc = $row['numero_documento'];
                $p_estado = $row['estado'];
                $p_procedencia = $row['procedencia'];
                $p_observaciones = $row['observaciones'];
                $p_usuario_responsable = $row['usuario_responsable'];
                $p_ubicacion = $row['ubicacion'];
                $raw_fecha_registro = trim((string)($row['fecha_registro'] ?? ''));
                if ($raw_fecha_registro === '') {
                    $p_fecha_registro = date('Y-m-d');
                } else {
                    if (strpos($raw_fecha_registro, '/') !== false) {
                        $parts = explode('/', $raw_fecha_registro);
                        if (count($parts) === 3) {
                            $p_fecha_registro = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                        } else {
                            $ts = strtotime($raw_fecha_registro);
                            $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                        }
                    } else {
                        $ts = strtotime($raw_fecha_registro);
                        $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                    }
                }
                $p_inventario_id = (string)$inventario_id_sync;
                $stmt->bind_param('sssssssssssssssssssss',
                    $p_nivel,
                    $p_aula,
                    $p_denominacion,
                    $p_marca,
                    $p_modelo,
                    $p_tipo,
                    $p_color,
                    $p_serie,
                    $p_largo,
                    $p_ancho,
                    $p_alto,
                    $p_doc_alta,
                    $p_fecha_compra,
                    $p_num_doc,
                    $p_estado,
                    $p_procedencia,
                    $p_observaciones,
                    $p_usuario_responsable,
                    $p_ubicacion,
                    $p_fecha_registro,
                    $p_inventario_id
                );
            }
            $stmt->execute();
            $importados++;
        }
        fclose($handle);
        if (!empty($dry_run)) {
            $processing_end = microtime(true);
            $processing_ms = (int)(($processing_end - $processing_start) * 1000);
            $file_hash_val = $file_hash ?: null;
            $sync_id_candidate = isset($sync_id) && $sync_id !== '' ? $sync_id : (date('YmdHis') . '_' . substr($file_hash_val ?? '', 0, 8));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'mode' => 'dry-run',
                'total_rows' => $total_rows,
                'rows_valid' => $total_rows - $invalid_rows,
                'rows_invalid' => $invalid_rows,
                'would_import' => $would_import,
                'duplicados' => $duplicados,
                'by_aula' => $by_aula,
                'by_nivel' => $by_nivel,
                'by_marca' => $by_marca,
                'by_estado' => $by_estado,
                'file_hash' => $file_hash_val,
                'db_has_cantidad' => $has_cantidad,
                'sync_id_candidate' => $sync_id_candidate,
                'tiempo_procesamiento_ms' => $processing_ms,
                'sample' => $sample_rows
            ], JSON_UNESCAPED_UNICODE);
            $conn->close();
            exit;
        }
        // Registrar el resultado del sync en el log para evitar reprocesos
        $log_entry = [
            'timestamp' => date('c'),
            'hash' => $file_hash,
            'sync_id' => $sync_id,
            'anio' => (isset($anio_post) ? $anio_post : null),
            'inventario_id' => $inventario_id_sync,
            'importados' => $importados,
            'duplicados' => $duplicados,
            'source_user' => $_POST['source_user'] ?? '',
            'uploader_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        $sync_log[] = $log_entry;
        file_put_contents($sync_log_file, json_encode($sync_log, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        // Si existe la tabla 'syncs', insertar registro ahí también (opcional, para búsquedas más robustas)
        if (table_exists($conn, 'syncs')) {
            $stmt2 = $conn->prepare("INSERT INTO syncs (ts, sync_id, file_hash, anio, inventario_id, importados, duplicados, source_user, uploader_ip) VALUES (?,?,?,?,?,?,?,?,?)");
            if ($stmt2) {
                $ts = date('Y-m-d H:i:s');
                // bind_param requiere variables (por referencia) — preparar locales
                $sync_id_val = $sync_id;
                $file_hash_val = $file_hash;
                $anio_val = isset($log_entry['anio']) && $log_entry['anio'] !== null ? intval($log_entry['anio']) : 0;
                $inventario_id_val = isset($log_entry['inventario_id']) ? intval($log_entry['inventario_id']) : 0;
                $importados_val = isset($log_entry['importados']) ? intval($log_entry['importados']) : 0;
                $duplicados_val = isset($log_entry['duplicados']) ? intval($log_entry['duplicados']) : 0;
                $source_user_val = $log_entry['source_user'] ?? '';
                $uploader_ip_val = $log_entry['uploader_ip'] ?? '';
                $stmt2->bind_param('sssiissss', $ts, $sync_id_val, $file_hash_val, $anio_val, $inventario_id_val, $importados_val, $duplicados_val, $source_user_val, $uploader_ip_val);
                $stmt2->execute();
                $stmt2->close();
            }
        }

        echo "Importados: $importados. Duplicados: $duplicados.";
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'No se pudo leer el archivo CSV.';
    }
    $conn->close();
    exit;
}

    // Endpoint AJAX para mover registros a la papelera (inventario_trash) o deshacer
    if (isset($_POST['action']) && in_array($_POST['action'], ['move_to_trash','undo_trash','undo_by_action'])) {
        // Note: undo_by_action uses action_id instead of ids[]
        if ($_POST['action'] === 'undo_by_action') {
            header('Content-Type: application/json; charset=utf-8');
            $action_id = isset($_POST['action_id']) ? intval($_POST['action_id']) : 0;
            if (!$action_id) { echo json_encode(['ok'=>false,'message'=>'action_id inválido']); $conn->close(); exit; }
            // buscar la acción original
            $resAct = $conn->query("SELECT * FROM trash_actions WHERE id=".intval($action_id)." LIMIT 1");
            if (!$resAct || $resAct->num_rows === 0) { echo json_encode(['ok'=>false,'message'=>'Acción no encontrada']); $conn->close(); exit; }
            $act = $resAct->fetch_assoc();
            $ids = json_decode($act['ids_json'], true);
            if (!is_array($ids) || empty($ids)) { echo json_encode(['ok'=>false,'message'=>'No hay ids en la acción']); $conn->close(); exit; }
            $ids = array_map('intval', $ids); $ids = array_filter($ids, function($v){ return $v>0; });
            if (empty($ids)) { echo json_encode(['ok'=>false,'message'=>'Ids inválidos']); $conn->close(); exit; }
            $ids_list = implode(',', $ids);
            // Restaurar desde inventario_trash
            $conn->begin_transaction();
            $conn->query("INSERT INTO inventario SELECT * FROM inventario_trash WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id));
            $restored = $conn->affected_rows;
            $conn->query("DELETE FROM inventario_trash WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id));
            $deleted = $conn->affected_rows;
            $conn->commit();
            // Registrar undo_by_action
            $actor = $_SESSION['admin'] ?? ($_POST['source_user'] ?? 'web');
            $ids_json = $conn->real_escape_string(json_encode(array_values($ids), JSON_UNESCAPED_UNICODE));
            $meta = $conn->real_escape_string(json_encode(['original_action_id'=>$action_id,'restored'=>$restored,'deleted_from_trash'=>$deleted], JSON_UNESCAPED_UNICODE));
            $conn->query("INSERT INTO trash_actions (action_type, ids_json, user_actor, metadata) VALUES ('undo_by_action', '$ids_json', '" . $conn->real_escape_string($actor) . "', '$meta')");
            $new_action_id = $conn->insert_id ?: null;
            echo json_encode(['ok'=>true,'restored'=>$restored,'deleted_from_trash'=>$deleted,'action_id'=>$new_action_id], JSON_UNESCAPED_UNICODE);
            $conn->close(); exit;
        }
        // otherwise original flow expects ids[]
        if (!isset($_POST['ids']) || !is_array($_POST['ids'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'message'=>'No ids provided']); $conn->close(); exit; }
        $ids = array_map('intval', $_POST['ids']);
        $ids = array_filter($ids, function($v){ return $v>0; });
        header('Content-Type: application/json; charset=utf-8');
        $ids = array_map('intval', $_POST['ids']);
        $ids = array_filter($ids, function($v){ return $v>0; });
        if (empty($ids)) { echo json_encode(['ok'=>false,'message'=>'No hay ids válidos']); $conn->close(); exit; }
        $ids_list = implode(',', $ids);
        // Asegurar que la tabla inventario_trash existe (crear como copia estructural si no existe)
        if (!table_exists($conn,'inventario_trash')) {
            // Intentar crear tabla con la misma estructura
            $conn->query("CREATE TABLE IF NOT EXISTS inventario_trash LIKE inventario");
            // Añadir columna deleted_at si no existe
            if (!table_has_column($conn,'inventario_trash','deleted_at')) {
                $conn->query("ALTER TABLE inventario_trash ADD COLUMN deleted_at DATETIME NULL");
            }
        }
        // Asegurar tabla de auditoría para acciones de trash
        if (!table_exists($conn,'trash_actions')) {
            $conn->query("CREATE TABLE IF NOT EXISTS trash_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action_type VARCHAR(64) NOT NULL,
                ids_json TEXT NOT NULL,
                user_actor VARCHAR(100) DEFAULT NULL,
                ts DATETIME DEFAULT CURRENT_TIMESTAMP,
                metadata TEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if ($_POST['action'] === 'move_to_trash') {
            // Mover filas a trash y luego borrar
            $conn->begin_transaction();
            $insertSql = "INSERT INTO inventario_trash SELECT *, NOW() as deleted_at FROM inventario WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id);
            $conn->query($insertSql);
            $affected_move = $conn->affected_rows;
            $conn->query("DELETE FROM inventario WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id));
            $affected_del = $conn->affected_rows;
            $conn->commit();
            // Resumen por aula/nivel de las filas movidas (consultar trash)
            $res = $conn->query("SELECT aula_funcional, nivel, COUNT(*) as cnt FROM inventario_trash WHERE id IN ($ids_list) GROUP BY aula_funcional, nivel");
            $summary = [];
            while ($r = $res->fetch_assoc()) { $summary[] = $r; }
            // Registrar acción en trash_actions
            $actor = $_SESSION['admin'] ?? ($_POST['source_user'] ?? 'web');
            $ids_json = $conn->real_escape_string(json_encode(array_values($ids), JSON_UNESCAPED_UNICODE));
            $meta = $conn->real_escape_string(json_encode(['moved'=>$affected_move,'deleted'=>$affected_del,'summary'=>$summary], JSON_UNESCAPED_UNICODE));
            $conn->query("INSERT INTO trash_actions (action_type, ids_json, user_actor, metadata) VALUES ('move_to_trash', '$ids_json', '" . $conn->real_escape_string($actor) . "', '$meta')");
            $action_id = $conn->insert_id ?: null;
            echo json_encode(['ok'=>true,'moved'=>$affected_move,'deleted'=>$affected_del,'summary'=>$summary,'action_id'=>$action_id], JSON_UNESCAPED_UNICODE);
            $conn->close(); exit;
        } else {
            // undo_trash: mover de inventario_trash de vuelta a inventario (solo si inventario_id coincide)
            $conn->begin_transaction();
            // Insert back only rows that belong to this inventario (inventario_id column present)
            $conn->query("INSERT INTO inventario SELECT * FROM inventario_trash WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id));
            $restored = $conn->affected_rows;
            $conn->query("DELETE FROM inventario_trash WHERE id IN ($ids_list) AND inventario_id=".intval($inventario_id));
            $deleted = $conn->affected_rows;
            $conn->commit();
            // Registrar acción de undo
            $actor = $_SESSION['admin'] ?? ($_POST['source_user'] ?? 'web');
            $ids_json = $conn->real_escape_string(json_encode(array_values($ids), JSON_UNESCAPED_UNICODE));
            $meta = $conn->real_escape_string(json_encode(['restored'=>$restored,'deleted_from_trash'=>$deleted], JSON_UNESCAPED_UNICODE));
            $conn->query("INSERT INTO trash_actions (action_type, ids_json, user_actor, metadata) VALUES ('undo_trash', '$ids_json', '" . $conn->real_escape_string($actor) . "', '$meta')");
            $action_id = $conn->insert_id ?: null;
            echo json_encode(['ok'=>true,'restored'=>$restored,'deleted_from_trash'=>$deleted,'action_id'=>$action_id], JSON_UNESCAPED_UNICODE);
            $conn->close(); exit;
        }
    }

// Procesar importación CSV
if (isset($_POST['importar']) && isset($_FILES['csv']) && $_FILES['csv']['error'] == 0 && $inventario_estado=='activo') {
    $archivo = $_FILES['csv']['tmp_name'];
    // Autodetectar delimitador y manejar BOM
    $detected_delim = ',';
    $handle = fopen($archivo, 'r');
    if ($handle) {
        // Leer primeras N líneas para detectar el delimitador
        $candidates = [',',';','\t','|'];
        $counts = array_fill_keys($candidates, 0);
        $sampleLines = [];
        $maxLines = 8;
        for ($i=0; $i < $maxLines && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($line === false) break;
            $sampleLines[] = $line;
            foreach ($candidates as $d) {
                // Count occurrences of delimiter in the line
                $counts[$d] += substr_count($line, $d);
            }
        }
        // Choose delimiter with highest total occurrences, but default to comma
        arsort($counts);
        $best = array_keys($counts)[0];
        if ($counts[$best] > 0) $detected_delim = $best;
        
        // LOG: Registrar delimitador detectado
        @file_put_contents(__DIR__ . '/debug_import_aula.log', "[IMPORT MANUAL] Delimitador detectado: '$detected_delim' - Conteos: " . json_encode($counts) . "\n", FILE_APPEND);
        
        // Rewind handle to read header using the detected delimiter
        rewind($handle);
        // Read header line raw and strip possible UTF-8 BOM
        $rawHeader = fgets($handle);
        if ($rawHeader === false) { fclose($handle); $handle = null; }
        else {
            $rawHeader = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader);
            // Parse header with detected delimiter
            $encabezados = str_getcsv($rawHeader, $detected_delim);
            
            // LOG: Registrar encabezados detectados
            @file_put_contents(__DIR__ . '/debug_import_aula.log', "[IMPORT MANUAL] Encabezados detectados: " . json_encode($encabezados) . "\n", FILE_APPEND);
        }
        // Normalizar encabezados (mismo tratamiento que en sync)
        $normalized = [];
        foreach ($encabezados as $h) {
            $k = mb_strtolower(trim($h));
            $k = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $k);
            $k = preg_replace('/[^a-z0-9_]/u', '_', $k);
            if ($k === 'aula') $k = 'aula_funcional';
            if ($k === 'denominacion' || $k === 'denominaci_n') $k = 'denominacion';
            if ($k === 'usuario' || $k === 'usuario_responsable') $k = 'usuario_responsable';
            $normalized[] = $k;
        }
        $encabezados = $normalized;
        // prepare to use fgetcsv with detected delimiter for remaining rows
        // (handle is currently positioned after header)
        $by_aula = [];
        $by_nivel = [];
        $by_marca = [];
        $by_estado = [];
        $processing_start = microtime(true);
        // Validar que la cabecera contenga columnas requeridas antes de procesar
        $required_headers = ['aula_funcional','nivel','denominacion'];
        $missing_headers = array_values(array_diff($required_headers, $encabezados));
        if (!empty($missing_headers)) {
            $msg = 'Faltan encabezados obligatorios: ' . implode(', ', $missing_headers);
            if ($dry_run) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['mode'=>'dry-run','error'=>'missing_headers','missing_headers'=>$missing_headers,'message'=>$msg], JSON_UNESCAPED_UNICODE);
                fclose($handle);
                $conn->close();
                exit;
            } else {
                header('HTTP/1.1 400 Bad Request');
                echo "Error: $msg\n";
                fclose($handle);
                $conn->close();
                exit;
            }
        }
        // Inicializar contadores locales para este import
        $importados = 0;
        $duplicados = 0;
        // Inicializar contadores/arrays usados durante el procesamiento
        $would_import = 0;
        $sample_rows = [];
        $duplicados = 0;
        // PRE-SCAN para detectar filas con columnas faltantes/extras
        $headerCount = count($encabezados);
        $pos_after_header = ftell($handle);
        $total_rows = 0;
        $bad_samples = [];
        $invalid_rows = 0;
        while (($rawLine = fgets($handle)) !== false) {
            $rawLine = cleanMalformedCSVLine($rawLine);
            $peek = str_getcsv($rawLine, $detected_delim);
            $total_rows++;
            $cnt = is_array($peek) ? count($peek) : 0;
            if ($cnt !== $headerCount) {
                $invalid_rows++;
                if (count($bad_samples) < 10) $bad_samples[] = ['line' => $total_rows + 1, 'columns_found' => $cnt, 'sample' => $peek];
            }
        }
        $dry_run = !empty($_POST['dry_run']) && ($_POST['dry_run'] === '1' || strtolower($_POST['dry_run']) === 'true');
        $autofix = !empty($_POST['autofix']) && ($_POST['autofix'] === '1' || strtolower($_POST['autofix']) === 'true');
        // Guardar copia del archivo inválido para auditoría si se detectaron filas inválidas
        $backup_url = null;
        if ($invalid_rows > 0) {
            $backup_dir = __DIR__ . '/backups/import_errors';
            if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
            $backup_name = 'invalid_' . date('Ymd_His') . '_' . substr(md5(uniqid()),0,8) . '.csv';
            @copy($archivo, $backup_dir . '/' . $backup_name);
            $backup_url = 'backups/import_errors/' . $backup_name;
        }
        if ($invalid_rows > 0 && $dry_run) {
            // Si el usuario pidió ver la corrección conservadora (autofix), generar las propuestas
            $proposed = [];
            if ($autofix && !empty($bad_samples)) {
                foreach ($bad_samples as $bs) {
                    $orig = $bs['sample'];
                    $cnt = is_array($orig) ? count($orig) : 0;
                    $corrected = $orig;
                    $action = '';
                    $details = '';
                    if ($cnt < $headerCount) {
                        $pad = array_fill(0, $headerCount - $cnt, '');
                        $corrected = array_merge($orig, $pad);
                        $action = 'pad';
                        $details = 'Se rellenaron ' . ($headerCount - $cnt) . ' columnas con valores vacíos.';
                    } elseif ($cnt > $headerCount) {
                        $first = array_slice($orig, 0, $headerCount-1);
                        $extra = array_slice($orig, $headerCount-1);
                        $last = implode(' | ', $extra);
                        $corrected = array_merge($first, [$last]);
                        $action = 'merge_extra';
                        $details = 'Se unieron ' . count($extra) . ' columnas extra en la última columna.';
                    }
                    // intentar asociar a encabezados
                    $assoc = safe_assoc($encabezados, $corrected);
                    $proposed[] = [
                        'line' => $bs['line'],
                        'columns_found' => $bs['columns_found'],
                        'action' => $action,
                        'details' => $details,
                        'original_sample' => $orig,
                        'corrected_row' => $assoc
                    ];
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'mode' => 'dry-run',
                'total_rows' => $total_rows,
                'rows_invalid' => $invalid_rows,
                'rows_valid' => max(0, $total_rows - $invalid_rows),
                'bad_samples' => $bad_samples,
                'headers' => $encabezados,
                'autofix_available' => true,
                'autofix_enabled' => $autofix,
                'backup_url' => $backup_url,
                'proposed_corrections' => $proposed,
                'message' => "Se detectaron filas con columnas inconsistentes. Activa 'Intentar corregir' para aplicar una corrección conservadora (rellenar columnas faltantes)."
            ], JSON_UNESCAPED_UNICODE);
            fclose($handle);
            $conn->close();
            exit;
        }
        if ($invalid_rows > 0 && !$dry_run && !$autofix) {
            header('HTTP/1.1 400 Bad Request');
            echo "Error: El archivo CSV tiene filas con diferente número de columnas que la cabecera. Para importar, usa Dry-run y corrige el archivo o activa 'Intentar corregir'.\n";
            echo "Filas inválidas: $invalid_rows. Se ha guardado una copia en: $backup_url\n";
            fclose($handle);
            $conn->close();
            exit;
        }
        // Volver al inicio de los datos para procesar normalmente
        fseek($handle, $pos_after_header);
        $total_rows = 0;
        // En el bucle de procesamiento aplicaremos autofix conservador si es necesario
        $corrections = [];
        while (($rawLine = fgets($handle)) !== false) {
            $rawLine = cleanMalformedCSVLine($rawLine);
            $data = str_getcsv($rawLine, $detected_delim);
            $total_rows++;
            $cnt = is_array($data) ? count($data) : 0;
            if ($cnt !== count($encabezados)) {
                if ($autofix) {
                    if ($cnt < count($encabezados)) {
                        $pad = array_fill(0, count($encabezados) - $cnt, '');
                        $corrected_row = array_merge($data, $pad);
                        $details = 'pad ' . (count($encabezados) - $cnt) . ' cols';
                    } else {
                        $first = array_slice($data, 0, count($encabezados)-1);
                        $extra = array_slice($data, count($encabezados)-1);
                        $last = implode(' | ', $extra);
                        $corrected_row = array_merge($first, [$last]);
                        $details = 'merge ' . count($extra) . ' extra cols';
                    }
                    // registrar corrección aplicada (usar array asociativo PHP)
                    $corrections[] = [
                        'row_number' => $total_rows + 1,
                        'original_columns' => $cnt,
                        'action' => ($cnt < count($encabezados)) ? 'pad' : 'merge_extra',
                        'details' => $details,
                        'original' => $data,
                        'corrected' => $corrected_row
                    ];
                    // aplicar la corrección a $data para continuar el procesamiento
                    $data = $corrected_row;
                } else {
                    // no debería llegar aquí si validamos antes; marcar y saltar
                    $invalid_rows++;
                    if (count($bad_samples) < 10) $bad_samples[] = ['line' => $total_rows + 1, 'columns_found' => $cnt, 'sample' => $data];
                    continue;
                }
            }
            $tmp = safe_assoc($encabezados, $data);
            $expected = ['nivel','aula_funcional','denominacion','marca','modelo','tipo','color','serie','largo','ancho','alto','documento_alta','fecha_compra','numero_documento','estado','procedencia','observaciones','usuario_responsable','ubicacion','fecha_registro','cantidad'];
            $row = [];
            foreach ($expected as $k) { $row[$k] = isset($tmp[$k]) ? $tmp[$k] : ''; }
            
            // DEBUG: Log para rastrear valores de aula_funcional
            if (!$dry_run && ($total_rows <= 3 || in_array($row['aula_funcional'], ['18', '19', 'Aula 18', 'Aula 19']))) {
                $log_entry = "Row $total_rows: aula_funcional='{$row['aula_funcional']}', nivel='{$row['nivel']}', denominacion='{$row['denominacion']}'\n";
                $log_entry .= "  tmp array keys: " . implode(', ', array_keys($tmp)) . "\n";
                $log_entry .= "  CSV data: " . json_encode($data) . "\n";
                $log_entry .= "  Headers: " . json_encode($encabezados) . "\n\n";
                @file_put_contents(__DIR__ . '/debug_import_aula.log', $log_entry, FILE_APPEND);
            }
            
            $cantidad = isset($row['cantidad']) ? intval($row['cantidad']) : 1;
            if (!$has_cantidad) {
                $row['observaciones'] = trim(($row['observaciones'] ?? '') . ' (cantidad: ' . $cantidad . ')');
            }
            // Criterio de duplicidad: nivel, aula_funcional, denominacion, serie, inventario_id
            $nivel = $conn->real_escape_string($row['nivel']);
            $aula = $conn->real_escape_string($row['aula_funcional']);
            $denom = $conn->real_escape_string($row['denominacion']);
            $serie = $conn->real_escape_string($row['serie']);
            $sql_check = "SELECT id FROM inventario WHERE nivel='$nivel' AND aula_funcional='$aula' AND denominacion='$denom' AND serie='$serie' AND inventario_id=$inventario_id LIMIT 1";
            $res = $conn->query($sql_check);
            if ($res && $res->num_rows > 0) {
                $duplicados++;
                if ($aula) $by_aula[$aula] = ($by_aula[$aula] ?? 0) + 1;
                if ($nivel) $by_nivel[$nivel] = ($by_nivel[$nivel] ?? 0) + 1;
                if (!empty($row['marca'])) $by_marca[$row['marca']] = ($by_marca[$row['marca']] ?? 0) + 1;
                if (!empty($row['estado'])) $by_estado[$row['estado']] = ($by_estado[$row['estado']] ?? 0) + 1;
                if (count($sample_rows) < 10) $sample_rows[] = ['row'=>$row,'status'=>'duplicate'];
                continue;
            }
            if ($dry_run) {
                $would_import++;
                if ($aula) $by_aula[$aula] = ($by_aula[$aula] ?? 0) + 1;
                if ($nivel) $by_nivel[$nivel] = ($by_nivel[$nivel] ?? 0) + 1;
                if (!empty($row['marca'])) $by_marca[$row['marca']] = ($by_marca[$row['marca']] ?? 0) + 1;
                if (!empty($row['estado'])) $by_estado[$row['estado']] = ($by_estado[$row['estado']] ?? 0) + 1;
                if (count($sample_rows) < 10) $sample_rows[] = ['row'=>$row,'status'=>'would_import'];
                continue;
            }
            // Insertar
            // Si la tabla tiene columna 'cantidad' insertarla directamente para evitar actualizaciones posteriores
            $largo = (isset($row['largo']) && is_numeric($row['largo'])) ? (float)$row['largo'] : 0.0;
            $ancho = (isset($row['ancho']) && is_numeric($row['ancho'])) ? (float)$row['ancho'] : 0.0;
            $alto  = (isset($row['alto'])  && is_numeric($row['alto']))  ? (float)$row['alto']  : 0.0;
            $cantidad_val = intval($cantidad);
            if ($has_cantidad) {
                $sql = "INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, cantidad, inventario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                // Preparar variables locales para bind_param (debe recibir variables por referencia)
                $p_nivel = $row['nivel'];
                $p_aula = $row['aula_funcional'];
                $p_denominacion = $row['denominacion'];
                $p_marca = $row['marca'];
                $p_modelo = $row['modelo'];
                $p_tipo = $row['tipo'];
                $p_color = $row['color'];
                $p_serie = $row['serie'];
                $p_largo = (string)$largo;
                $p_ancho = (string)$ancho;
                $p_alto = (string)$alto;
                $p_documento_alta = $row['documento_alta'];
                $p_fecha_compra = $row['fecha_compra'];
                $p_numero_documento = $row['numero_documento'];
                $p_estado = $row['estado'];
                $p_procedencia = $row['procedencia'];
                $p_observaciones = $row['observaciones'];
                $p_usuario_responsable = $row['usuario_responsable'];
                $p_ubicacion = $row['ubicacion'];
                $raw_fecha_registro = trim((string)($row['fecha_registro'] ?? ''));
                if ($raw_fecha_registro === '') {
                    $p_fecha_registro = date('Y-m-d');
                } else {
                    if (strpos($raw_fecha_registro, '/') !== false) {
                        $parts = explode('/', $raw_fecha_registro);
                        if (count($parts) === 3) {
                            $p_fecha_registro = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                        } else {
                            $ts = strtotime($raw_fecha_registro);
                            $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                        }
                    } else {
                        $ts = strtotime($raw_fecha_registro);
                        $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                    }
                }
                $p_cantidad = (string)$cantidad_val;
                $p_inventario_id = (string)$inventario_id;
                // Bind all as strings for simplicity (MySQL will cast types as needed)
                $stmt->bind_param('ssssssssssssssssssssss',
                    $p_nivel,
                    $p_aula,
                    $p_denominacion,
                    $p_marca,
                    $p_modelo,
                    $p_tipo,
                    $p_color,
                    $p_serie,
                    $p_largo,
                    $p_ancho,
                    $p_alto,
                    $p_documento_alta,
                    $p_fecha_compra,
                    $p_numero_documento,
                    $p_estado,
                    $p_procedencia,
                    $p_observaciones,
                    $p_usuario_responsable,
                    $p_ubicacion,
                    $p_fecha_registro,
                    $p_cantidad,
                    $p_inventario_id
                );
            } else {
                $sql = "INSERT INTO inventario (nivel, aula_funcional, denominacion, marca, modelo, tipo, color, serie, largo, ancho, alto, documento_alta, fecha_compra, numero_documento, estado, procedencia, observaciones, usuario_responsable, ubicacion, fecha_registro, inventario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                // Preparar variables locales para bind_param (debe recibir variables por referencia)
                $p_nivel = $row['nivel'];
                $p_aula = $row['aula_funcional'];
                $p_denominacion = $row['denominacion'];
                $p_marca = $row['marca'];
                $p_modelo = $row['modelo'];
                $p_tipo = $row['tipo'];
                $p_color = $row['color'];
                $p_serie = $row['serie'];
                $p_largo = (string)$largo;
                $p_ancho = (string)$ancho;
                $p_alto = (string)$alto;
                $p_documento_alta = $row['documento_alta'];
                $p_fecha_compra = $row['fecha_compra'];
                $p_numero_documento = $row['numero_documento'];
                $p_estado = $row['estado'];
                $p_procedencia = $row['procedencia'];
                $p_observaciones = $row['observaciones'];
                $p_usuario_responsable = $row['usuario_responsable'];
                $p_ubicacion = $row['ubicacion'];
                $raw_fecha_registro = trim((string)($row['fecha_registro'] ?? ''));
                if ($raw_fecha_registro === '') {
                    $p_fecha_registro = date('Y-m-d');
                } else {
                    if (strpos($raw_fecha_registro, '/') !== false) {
                        $parts = explode('/', $raw_fecha_registro);
                        if (count($parts) === 3) {
                            $p_fecha_registro = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                        } else {
                            $ts = strtotime($raw_fecha_registro);
                            $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                        }
                    } else {
                        $ts = strtotime($raw_fecha_registro);
                        $p_fecha_registro = $ts ? date('Y-m-d', $ts) : $raw_fecha_registro;
                    }
                }
                $p_inventario_id = (string)$inventario_id;
                $stmt->bind_param('sssssssssssssssssssss',
                    $p_nivel,
                    $p_aula,
                    $p_denominacion,
                    $p_marca,
                    $p_modelo,
                    $p_tipo,
                    $p_color,
                    $p_serie,
                    $p_largo,
                    $p_ancho,
                    $p_alto,
                    $p_documento_alta,
                    $p_fecha_compra,
                    $p_numero_documento,
                    $p_estado,
                    $p_procedencia,
                    $p_observaciones,
                    $p_usuario_responsable,
                    $p_ubicacion,
                    $p_fecha_registro,
                    $p_inventario_id
                );
            }
            $stmt->execute();
            $importados++;
        }
        fclose($handle);
        // Si hubo correcciones aplicadas en import real, persistir log de correcciones
        if (!$dry_run && !empty($corrections)) {
            $backup_dir = __DIR__ . '/backups/import_errors';
            if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
            $cor_file = 'corrections_' . date('Ymd_His') . '_' . substr(md5(uniqid()),0,8) . '.json';
            file_put_contents($backup_dir . '/' . $cor_file, json_encode($corrections, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $cor_file_url = 'backups/import_errors/' . $cor_file;
            // anexar info al mensaje final
            if (isset($mensaje)) $mensaje .= " Correcciones aplicadas registradas en: $cor_file_url"; else $mensaje = "Correcciones aplicadas registradas en: $cor_file_url";
        }
        if (!empty($dry_run)) {
            $processing_end = microtime(true);
            $processing_ms = (int)(($processing_end - $processing_start) * 1000);
            $file_hash_val = @md5_file($archivo) ?: null;
            $sync_id_candidate = date('YmdHis') . '_' . substr($file_hash_val ?? '', 0, 8);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'mode'=>'dry-run',
                'total_rows'=>$total_rows,
                'rows_valid' => $total_rows - $invalid_rows,
                'rows_invalid' => $invalid_rows,
                'would_import'=>$would_import,
                'duplicados'=>$duplicados,
                'by_aula' => $by_aula,
                'by_nivel' => $by_nivel,
                'by_marca' => $by_marca,
                'by_estado' => $by_estado,
                'file_hash' => $file_hash_val,
                'db_has_cantidad' => $has_cantidad,
                'sync_id_candidate' => $sync_id_candidate,
                'tiempo_procesamiento_ms' => $processing_ms,
                'sample' => $sample_rows
            ], JSON_UNESCAPED_UNICODE);
            $conn->close();
            exit;
        }
        $mensaje = "Importados: $importados. Duplicados: $duplicados.";
        // Registrar import manual en el log (solo para auditoría interna)
        $sync_log_file = __DIR__ . '/sync_log.json';
        if (!file_exists($sync_log_file)) file_put_contents($sync_log_file, json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $sync_log = json_decode(file_get_contents($sync_log_file), true) ?: [];
        $log_entry = [
            'timestamp' => date('c'),
            'hash' => null,
            'sync_id' => 'manual_import',
            'anio' => $anios && isset($anios[0]) ? ($anios[0]['anio'] ?? null) : null,
            'inventario_id' => $inventario_id,
            'importados' => $importados,
            'duplicados' => $duplicados,
            'source_user' => $_SESSION['admin'] ?? 'web',
            'uploader_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        $sync_log[] = $log_entry;
        file_put_contents($sync_log_file, json_encode($sync_log, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if (table_exists($conn, 'syncs')) {
            $stmt2 = $conn->prepare("INSERT INTO syncs (ts, sync_id, file_hash, anio, inventario_id, importados, duplicados, source_user, uploader_ip) VALUES (?,?,?,?,?,?,?,?,?)");
            if ($stmt2) {
                $ts = date('Y-m-d H:i:s');
                // bind_param requires variables (passed by reference). No pasar expresiones o elementos de arrays directamente.
                $sync_id_val = $log_entry['sync_id'] ?? '';
                $file_hash_val = '';
                $anio_val = isset($log_entry['anio']) && $log_entry['anio'] !== null ? intval($log_entry['anio']) : 0;
                $inventario_id_val = isset($log_entry['inventario_id']) ? intval($log_entry['inventario_id']) : 0;
                $importados_val = isset($log_entry['importados']) ? intval($log_entry['importados']) : 0;
                $duplicados_val = isset($log_entry['duplicados']) ? intval($log_entry['duplicados']) : 0;
                $source_user_val = $log_entry['source_user'] ?? '';
                $uploader_ip_val = $log_entry['uploader_ip'] ?? '';
                // Tipos: s = string, i = integer
                $stmt2->bind_param('sssiiisss', $ts, $sync_id_val, $file_hash_val, $anio_val, $inventario_id_val, $importados_val, $duplicados_val, $source_user_val, $uploader_ip_val);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    } else {
        $mensaje = 'No se pudo leer el archivo CSV.';
    }
}

// Consultar inventario
// No cargar el inventario completo al inicio
$result = false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Colegio Fe y Alegría 44 - Multi-Anual</title>
    <style>
        :root{ --primary:#b71c1c; --primary-dark:#8b0000; --primary-light:#e53935; --accent:#ff6f00; --success:#2e7d32; --card-bg:#ffffff; --text:#1a1a1a; --border:#e0e0e0; }
        *{box-sizing:border-box}
        body{font-family:'Segoe UI', 'Helvetica Neue', Arial, sans-serif; margin:0; padding:0; color:var(--text); background:#f5f5f5 url('fachada_fya44.jpg') center/cover no-repeat fixed; min-height:100vh; -webkit-font-smoothing:antialiased}
        body::before{content:'';position:fixed;top:0;left:0;right:0;bottom:0;background:linear-gradient(135deg, rgba(183,28,28,0.05) 0%, rgba(255,255,255,0.92) 50%, rgba(25,118,210,0.05) 100%);z-index:-1}
        .cabecera{display:flex;align-items:center;gap:16px;padding:14px 20px;position:sticky;top:0;z-index:1200;background:linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 50%, var(--primary-light) 100%);box-shadow:0 6px 24px rgba(0,0,0,0.18),0 2px 8px rgba(183,28,28,0.3);border-bottom:3px solid var(--accent)}
        .brand{display:flex;align-items:center;gap:16px}
        .brand img{height:75px;width:auto;background:#fff;padding:10px;border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,0.3),0 0 0 3px rgba(255,255,255,0.3);border:2px solid var(--accent);display:block;transition:transform 0.3s ease}
        .brand img:hover{transform:scale(1.05)}
        .brand h2{margin:0;color:#fff;font-size:1.6rem;font-weight:800;text-shadow:2px 2px 6px rgba(0,0,0,0.4),0 0 20px rgba(255,111,0,0.3);letter-spacing:0.5px}
        .cab-actions{margin-left:auto;display:flex;gap:10px;align-items:center}
        .hamburger{display:none;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,0.15);border:2px solid rgba(255,255,255,0.3);color:#fff;font-size:1.3rem;cursor:pointer;transition:all 0.3s;backdrop-filter:blur(10px)}
        .hamburger:hover{background:rgba(255,255,255,0.25);transform:scale(1.05)}
        .mobile-menu{display:none;position:absolute;right:16px;top:85px;min-width:220px;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);padding:10px;z-index:1300;border:2px solid var(--accent)}
        .mobile-menu a{display:block;padding:10px 12px;color:var(--primary);text-decoration:none;border-radius:8px;transition:all 0.2s;font-weight:600}
        .mobile-menu a:hover{background:linear-gradient(90deg,var(--primary),var(--primary-light));color:#fff}
        .btn{padding:10px 18px;border-radius:10px;border:0;cursor:pointer;font-weight:700;font-size:0.95rem;transition:all 0.3s;text-decoration:none;display:inline-block;box-shadow:0 4px 12px rgba(0,0,0,0.15)}
        .btn-primary{background:linear-gradient(135deg,#fff 0%,#f0f0f0 100%);color:var(--primary);border:2px solid rgba(255,255,255,0.4)}
        .btn-primary:hover{background:#fff;transform:translateY(-2px);box-shadow:0 6px 20px rgba(255,255,255,0.4)}
        main{max-width:1300px;margin:20px auto;padding:0 16px 60px}
        .panel{background:var(--card-bg);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,0.1),0 2px 8px rgba(0,0,0,0.06);padding:20px;margin-bottom:20px;border:1px solid var(--border)}
        .año-control{display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:10px;background:linear-gradient(135deg,#fff9f9 0%,#f5f5f5 100%);border-radius:10px;border-left:4px solid var(--accent)}
        form.inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        input,select,textarea{padding:10px 12px;border-radius:10px;border:2px solid var(--border);font-size:0.96rem;background:#fff;color:var(--text);transition:all 0.3s}
        input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(183,28,28,0.1);outline:none}
        label{color:var(--primary);font-weight:700;font-size:0.96rem}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
        table{width:100%;border-collapse:separate;border-spacing:0;font-size:0.95rem;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.08)}
        th,td{padding:10px 12px;border-bottom:1px solid #f0f0f0;text-align:left;vertical-align:top}
        th{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-light) 100%);color:#fff;font-weight:700;text-shadow:1px 1px 2px rgba(0,0,0,0.2)}
        tr:nth-child(even) td{background:#fafafa}
        tr:hover td{background:#fff3e0}
        .tabla-scroll{overflow:auto;border-radius:12px}
        .msg{padding:12px 16px;border-radius:10px;margin-bottom:10px;border-left:4px solid;font-weight:600}
        .msg.success{background:#e8f5e9;color:var(--success);border-color:var(--success)}
        .msg.warn{background:#ffebee;color:var(--primary);border-color:var(--primary)}
        @media (max-width:900px){ .brand h2{font-size:1.15rem} .brand img{height:62px} .grid-2,.grid-3{grid-template-columns:1fr} .cab-actions{display:none} .hamburger{display:inline-block} .cabecera{padding:10px 14px} table,th,td{font-size:0.9rem} }
    </style>
</head>
<body>
    <div class="cabecera">
        <div class="brand">
            <img src="logo_fya44.png" alt="Logo Fe y Alegría 44">
            <h2>Inventario del Colegio Fe y Alegría 44</h2>
        </div>
        <div class="cab-actions">
            <a href="reporte_inventario.php" class="btn btn-primary">Ver Reportes</a>
            <a href="reporte_rapido_aulas.php" class="btn btn-primary" style="background:#1976d2;">📄 Reporte Rápido</a>
            <?php if (!empty($_SESSION['admin'])): ?>
                <a href="reubicar_items.php" class="btn btn-primary">🔄 Reubicar Items</a>
                <a href="inventario_central.php?sync_log=1" class="btn btn-primary">Historial Sync</a>
                <a href="inventario_central.php?manage_senders=1" class="btn btn-primary">Gestión Envíos</a>
            <?php endif; ?>
        </div>
        <button id="hamburger" class="hamburger" aria-label="Abrir menú">☰</button>
        <div id="mobileMenu" class="mobile-menu" aria-hidden="true">
            <a href="reporte_inventario.php" class="btn">Ver Reportes</a>
            <a href="reporte_rapido_aulas.php" class="btn" style="background:#1976d2;color:#fff;">📄 Reporte Rápido</a>
            <?php if (!empty($_SESSION['admin'])): ?>
                <a href="reubicar_items.php" class="btn">🔄 Reubicar Items</a>
                <a href="inventario_central.php?sync_log=1" class="btn">Historial Sync</a>
                <a href="inventario_central.php?manage_senders=1" class="btn">Gestión Envíos</a>
            <?php endif; ?>
        </div>
    </div>
    <script>
    // Helper: extraer cantidad desde observaciones si la fila no tiene campo 'cantidad' en la BD
    function extractCantidadFromObservaciones(obs) {
        if (!obs) return null;
        try {
            // Buscar '(cantidad: 12)' o 'cantidad: 12'
            var m = obs.match(/\(cantidad:\s*(\d+)\)/i);
            if (m && m[1]) return parseInt(m[1], 10);
            m = obs.match(/cantidad[:\s]+(\d+)/i);
            if (m && m[1]) return parseInt(m[1], 10);
        } catch(e) { return null; }
        return null;
    }
    function getRowCantidad(row) {
        if (!row) return '';
        if (row.cantidad !== undefined && row.cantidad !== null && String(row.cantidad).trim() !== '') return row.cantidad;
        var c = extractCantidadFromObservaciones(row.observaciones || '');
        return c !== null ? c : '';
    }
        (function(){
            var btn = document.getElementById('hamburger');
            var menu = document.getElementById('mobileMenu');
            if(!btn || !menu) return;
            btn.addEventListener('click', function(e){
                var shown = menu.style.display === 'block';
                menu.style.display = shown ? 'none' : 'block';
                menu.setAttribute('aria-hidden', shown ? 'true' : 'false');
            });
            // cerrar al click fuera
            document.addEventListener('click', function(e){
                if(!menu.contains(e.target) && !btn.contains(e.target)) { menu.style.display='none'; menu.setAttribute('aria-hidden','true'); }
            });
        })();
    </script>
    <main>
    
    <div class="año-control">
        <div class="subtitulo" style="display:inline-block;">
            Inventario 
            <form method="get" style="display:inline-block;margin-left:12px;">
                <select name="anio" id="selectAnio" onchange="this.form.submit()" style="font-size:1.1em;padding:2px 8px;">
                    <?php foreach($anios as $inv): ?>
                        <option value="<?= $inv['anio'] ?>" data-inventario-id="<?= $inv['id'] ?>" <?= ($inv['id']==$inventario_id)?'selected':'' ?>><?= $inv['anio'] ?> <?= $inv['estado']=='cerrado'?'(CERRADO)':'' ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if($inventario_estado=='cerrado'): ?>
                <span style="color:#b71c1c;font-weight:bold;">(CERRADO)</span>
            <?php endif; ?>
        </div>
        <br>

        <form method="post" id="formNuevoAnio" style="display:inline-block;margin-top:8px;" onsubmit="return confirmarCrearAnio();">
            <input type="number" name="anio_nuevo" id="anio_nuevo" min="2020" max="2100" placeholder="Nuevo año" required style="width:100px;">
            <button type="submit" name="nuevo_anio" class="btn" id="btnNuevoAnio">Crear nuevo año</button>
        </form>
        <?php if($inventario_estado=='activo'): ?>
            <form method="post" style="display:inline-block;margin-left:12px;">
                <button type="submit" name="cerrar_anio" class="btn" onclick="return confirm('¿Cerrar el año actual? No se podrá editar más.');" style="background:#ff9800;">Cerrar año actual</button>
            </form>
        <?php endif; ?>
    </div>

    <section class="panel" style="max-width:600px; margin:20px auto 0;">
        <h3 style="margin-top:0;">Gestión de Aulas por Nivel</h3>
        <form method="post" style="margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
            <label>Nivel:
                <select name="nivel_aula" id="nivel_aula" required onchange="mostrarAulas()">
                    <option value="Inicial">Inicial</option>
                    <option value="Primaria">Primaria</option>
                    <option value="Secundaria">Secundaria</option>
                </select>
            </label>
            <label style="flex:1;">Nueva Aula:
                <input name="nueva_aula" placeholder="Ej: Aula 1, Dirección">
            </label>
            <button type="submit" name="gestionar_aulas" class="btn">Agregar Aula</button>
        </form>
        <form method="post" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
            <input type="hidden" name="nivel_aula" id="nivel_aula_eliminar" value="Inicial">
            <label style="flex:1;">Eliminar Aula:
                <select name="aula_eliminar" id="aula_eliminar_select"></select>
            </label>
            <button type="submit" name="eliminar_aula" class="btn" onclick="return confirm('¿Eliminar esta aula?')">Eliminar Aula</button>
        </form>
        <div id="lista_aulas" style="margin-top:12px; font-size:0.98em;"></div>
    </section>
    <script>
    // Mostrar aulas según nivel seleccionado
    const aulas = <?php echo json_encode($aulas, JSON_UNESCAPED_UNICODE); ?>;
    function mostrarAulas() {
        const nivel = document.getElementById('nivel_aula').value;
        const lista = aulas[nivel] || [];
        document.getElementById('nivel_aula_eliminar').value = nivel;
        // Actualizar select de eliminación
        const sel = document.getElementById('aula_eliminar_select');
        sel.innerHTML = '';
        lista.forEach(aula => {
            const opt = document.createElement('option');
            opt.value = aula;
            opt.textContent = aula;
            sel.appendChild(opt);
        });
        // Mostrar lista
        document.getElementById('lista_aulas').innerHTML = '<b>Aulas de ' + nivel + ':</b> ' + (lista.length ? lista.join(', ') : '<i>Sin aulas registradas</i>');
    }
    document.addEventListener('DOMContentLoaded', mostrarAulas);
    document.getElementById('nivel_aula').addEventListener('change', mostrarAulas);
    </script>
    
    <?php if($inventario_estado=='activo'): ?>
    <form method="post" enctype="multipart/form-data" id="formImportar">
        <label>Importar archivo CSV:
            <input type="file" name="csv" id="input_csv" accept=".csv" required>
        </label>
        <label style="margin-left:12px;">
            <input type="checkbox" name="dry_run" id="chk_dry_run" value="1"> Dry-run (previsualizar)
        </label>
        <label style="margin-left:12px;">
            <input type="checkbox" name="autofix" id="chk_autofix" value="1"> Intentar corregir columnas faltantes (pad)
        </label>
        <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
            <button type="button" id="btnPreview" class="btn">Previsualizar (Dry-run)</button>
            <button type="submit" name="importar" class="btn">Importar</button>
            <a href="inventario_central.php?download_template=1" class="btn" style="background:#fff;color:#b71c1c;border:1px solid #f0e0e0;">Descargar plantilla</a>
        </div>
    </form>
    <div id="dryrun_report" style="margin-top:12px;display:none;max-width:100%;"></div>
    <script>
    // Enviar dry-run usando fetch y mostrar el resumen bonito
    document.getElementById('btnPreview').addEventListener('click', function(e){
        const input = document.getElementById('input_csv');
        if (!input.files || !input.files.length) { alert('Selecciona un archivo CSV primero.'); return; }
        const fd = new FormData();
        fd.append('csv', input.files[0]);
        // Añadir año seleccionado del selector principal (si existe)
        const anioSelect = document.querySelector('select[name="anio"]');
        if (anioSelect) fd.append('anio', anioSelect.value);
        // Marcar como importación manual y dry-run
        fd.append('importar', '1');
        fd.append('dry_run', '1');
        // incluir opción de autofix si el usuario la marcó
        const autofixEl = document.getElementById('chk_autofix');
        if (autofixEl && autofixEl.checked) fd.append('autofix', '1');
        // Indicar que es import manual por web (no token)
        fetch('inventario_central.php', { method: 'POST', body: fd })
            .then(r => {
                const ct = r.headers.get('content-type') || '';
                if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t); });
                if (ct.indexOf('application/json') === -1) return r.text().then(t => { throw new Error('Respuesta no JSON: ' + t); });
                return r.json();
            })
            .then(data => renderDryRunReport(data))
            .catch(err => { alert('Error al ejecutar dry-run: ' + err.message); console.error(err); });
    });

    function renderDryRunReport(data) {
        const container = document.getElementById('dryrun_report');
        container.style.display = 'block';
        if (!data || data.mode !== 'dry-run') {
            container.innerHTML = '<div style="padding:12px;background:#ffecec;border:1px solid #f5c6cb;color:#721c24;border-radius:8px;">Respuesta inesperada del servidor.</div>';
            return;
        }
        // Construir HTML resumen
        let html = '<div style="padding:12px;border:1px solid #eee;border-radius:8px;background:#fff;">';
        html += `<h4 style="margin:0 0 8px 0;color:#b71c1c;">Dry-run: Resumen</h4>`;
        html += '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">';
        const cards = [
            ['Total filas', data.total_rows],
            ['Filas válidas', data.rows_valid],
            ['Filas inválidas', data.rows_invalid],
            ['Se importarían', data.would_import],
            ['Duplicados', data.duplicados]
        ];
        cards.forEach(c=>{ html += `<div style="background:#f7f7f7;padding:8px;border-radius:8px;min-width:120px;text-align:center;"><b>${c[1] ?? 0}</b><div style="font-size:0.9em;color:#666">${c[0]}</div></div>`; });
        html += '</div>';
        // Agregados por aula y nivel (compacto)
        html += '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">';
        html += renderKeyCountBlock('Por aula', data.by_aula);
        html += renderKeyCountBlock('Por nivel', data.by_nivel);
        html += renderKeyCountBlock('Por marca', data.by_marca);
        html += renderKeyCountBlock('Por estado', data.by_estado);
        html += '</div>';
        // Muestra de filas
        html += '<h4 style="margin:6px 0;color:#b71c1c;">Muestra (hasta 10 filas)</h4>';
        // Si el servidor devolvió bad_samples (errores de columnas) mostrarlos primero
        if (data.bad_samples && data.bad_samples.length) {
            html += '<div style="background:#fff6f6;padding:8px;border:1px solid #ffd6d6;border-radius:6px;margin-bottom:8px;">';
            html += '<b style="color:#b71c1c">Filas con columnas inconsistentes:</b>';
            html += '<ul id="bad_samples_list" style="margin:8px 0 0 14px;padding:0;font-size:0.95em;line-height:1.4;">';
            data.bad_samples.forEach(bs => { html += `<li>Línea ${bs.line}: ${bs.columns_found} columnas</li>`; });
            html += '</ul>';
            // Botón para descargar las filas inválidas como CSV
            if (data.headers) {
                html += '<div style="margin-top:8px;"><button id="btnDownloadInvalid" class="btn" style="background:#fff;color:#b71c1c;border:1px solid #ffd6d6;">Descargar filas inválidas</button></div>';
            }
            html += '</div>';
        }
        // Mostrar propuestas de corrección si las hay
        if (data.proposed_corrections && data.proposed_corrections.length) {
            html += '<div style="background:#f1f8ff;padding:8px;border:1px solid #cfe8ff;border-radius:6px;margin-bottom:8px;">';
            html += '<b style="color:#0b62a4">Correcciones propuestas</b>';
            html += '<div style="margin-top:8px;max-height:200px;overflow:auto;border-radius:6px;padding:6px;background:#fff;border:1px solid #eef6ff">';
            html += '<table style="width:100%;border-collapse:collapse;font-size:0.9em"><thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #eef">Línea</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eef">Acción</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eef">Detalles</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eef">Fila corregida</th></tr></thead><tbody>';
            data.proposed_corrections.forEach(pc => {
                html += `<tr><td style="padding:6px;border-bottom:1px solid #f0f">${pc.line}</td><td style="padding:6px;border-bottom:1px solid #f0f">${pc.action}</td><td style="padding:6px;border-bottom:1px solid #f0f">${pc.details}</td><td style="padding:6px;border-bottom:1px solid #f0f"><pre style="white-space:pre-wrap;margin:0;font-size:0.85em">${JSON.stringify(pc.corrected_row, null, 0)}</pre></td></tr>`;
            });
            html += '</tbody></table></div>';
            html += '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;"><button id="btnConfirmAutofix" class="btn" style="background:#0b62a4;color:#fff">Confirmar e importar con correcciones</button><span style="color:#666;font-size:0.9em">(Esto ejecutará el import real aplicando las correcciones propuestas)</span></div>';
            html += '</div>';
        }
        if (data.sample && data.sample.length) {
            html += '<div style="max-height:240px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:6px;background:#fafafa">';
            data.sample.forEach((s,idx)=>{
                html += `<div style="border-bottom:1px dashed #eee;padding:6px 2px;"><b>#${idx+1} - ${s.status}</b><pre style="white-space:pre-wrap;margin:6px 0 0 0;font-size:0.9em">${JSON.stringify(s.row, null, 2)}</pre></div>`;
            });
            html += '</div>';
        } else html += '<div style="color:#666">No hay muestras disponibles.</div>';
        html += `<div style="margin-top:10px;color:#666;font-size:0.9em">Procesamiento: ${data.tiempo_procesamiento_ms} ms — Hash: <span style="font-family:monospace">${data.file_hash||''}</span></div>`;
        html += '</div>';
        container.innerHTML = html;
        // Añadir handler para descarga de filas inválidas
        if (data.bad_samples && data.bad_samples.length && data.headers) {
            const btn = document.getElementById('btnDownloadInvalid');
            if (btn) {
                btn.addEventListener('click', function(){
                    // Construir CSV con encabezados y las filas inválidas
                    const headers = data.headers;
                    const rows = data.bad_samples.map(bs => bs.sample || []);
                    let csv = headers.join(',') + '\r\n';
                    rows.forEach(r => {
                        // Escapar comillas dobles
                        const line = r.map(v => '"' + String(v || '').replace(/"/g,'""') + '"').join(',');
                        csv += line + '\r\n';
                    });
                    const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'filas_invalidas_inventario.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                });
            }
        }
                // Añadir handler para confirmar import con autofix (si el servidor devolvió propuestas)
                const btnConfirm = document.getElementById('btnConfirmAutofix');
                if (btnConfirm) {
                    btnConfirm.addEventListener('click', function(){
                        if (!confirm('¿Confirmar e importar aplicando las correcciones propuestas? Esta acción insertará los datos en la base de datos.')) return;
                        const input = document.getElementById('input_csv');
                        if (!input.files || !input.files.length) { alert('Selecciona el archivo CSV primero.'); return; }
                        const fd2 = new FormData();
                        fd2.append('csv', input.files[0]);
                        const anioSelect = document.querySelector('select[name="anio"]');
                        if (anioSelect) fd2.append('anio', anioSelect.value);
                        fd2.append('importar', '1');
                        fd2.append('autofix', '1');
                        btnConfirm.disabled = true; btnConfirm.textContent = 'Importando...';
                        fetch('inventario_central.php', { method: 'POST', body: fd2 })
                            .then(r => r.text())
                            .then(txt => {
                                alert('Resultado del import:\n' + txt);
                                btnConfirm.disabled = false; btnConfirm.textContent = 'Confirmar e importar con correcciones';
                            })
                            .catch(err => { alert('Error al realizar import: ' + err.message); console.error(err); btnConfirm.disabled = false; btnConfirm.textContent = 'Confirmar e importar con correcciones'; });
                    });
                }
    }

    function renderKeyCountBlock(title, obj) {
        let html = `<div style="min-width:220px;max-width:420px;background:#fff;border:1px solid #f0f0f0;padding:8px;border-radius:8px;"><b style="color:#b71c1c">${title}</b>`;
        if (!obj || Object.keys(obj).length===0) { html += '<div style="color:#666;margin-top:6px">N/A</div></div>'; return html; }
        html += '<ul style="margin:8px 0 0 14px;padding:0;font-size:0.95em;line-height:1.4;">';
        Object.entries(obj).sort((a,b)=>b[1]-a[1]).slice(0,20).forEach(([k,v])=>{ html += `<li>${k}: <b>${v}</b></li>`; });
        html += '</ul></div>';
        return html;
    }
    </script>
    <?php else: ?>
    <div class="msg" style="background:#ffecb3;color:#b71c1c;border:1px solid #ffb300;">
        Este año está cerrado. No se puede importar ni editar bienes.
    </div>
    <?php endif; ?>
    
    <?php 
    // Mostrar mensaje de éxito tras redirección
    if (isset($_GET['copiados']) && isset($_GET['anio'])) {
        $copiados = intval($_GET['copiados']);
        $anio_nuevo = intval($_GET['anio']);
        $mensaje = "<b style='color:#006400;'>¡Año $anio_nuevo creado y $copiados bienes copiados correctamente!</b>";
    }
    if ($mensaje): ?>
        <div class="msg" id="msgImportar" style="font-size:1.1em;<?= strpos($mensaje,'¡Año')!==false?'background:#e8ffe8;color:#006400;border:1.5px solid #388e3c;':'background:#ffecb3;color:#b71c1c;border:1.5px solid #ffb300;' ?>"><?= $mensaje ?></div>
        <script>
        setTimeout(function(){
            var msg = document.getElementById('msgImportar');
            if(msg) msg.style.display = 'none';
        }, 3500);
        </script>
    <?php endif; ?>

    <h3 style="margin-top:0;">Inventario Centralizado</h3>
    <div class="panel" style="margin-bottom:18px;">
        <form id="filtrosInventario" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:flex-start;">
            <label>Nivel:
                <select name="nivel" id="filtro_nivel">
                    <option value="">Todos</option>
                    <option value="Inicial">Inicial</option>
                    <option value="Primaria">Primaria</option>
                    <option value="Secundaria">Secundaria</option>
                </select>
            </label>
            <label>Aula:
                <select name="aula" id="filtro_aula" style="width:110px;">
                    <option value="">Todas</option>
                </select>
            </label>
            <label>Denominación:
                <input name="denominacion" id="filtro_denominacion" placeholder="Ej: Mesa, Silla" style="width:110px;">
            </label>
            <button type="button" class="btn" id="btnMostrarInventario">Mostrar Inventario Total</button>
            <button type="button" id="btnImprimir" class="btn" style="background:transparent;color:#b71c1c;border:2px solid #b71c1c;padding:8px 18px;border-radius:8px;margin-left:12px;">imprimir</button>
        </form>
    </div>
    <div id="contenedorTablaInventario" style="min-height:120px;">
        <div style="color:#b71c1c;text-align:center;padding:30px 0;opacity:0.7;">
            <b>Haz clic en "Mostrar Inventario Total" para ver los registros.</b>
        </div>
    </div>

    <script>
    // Confirmación simple para crear nuevo año
    function confirmarCrearAnio() {
        var anio = document.getElementById('anio_nuevo').value;
        if (!anio || isNaN(anio) || anio < 2020 || anio > 2100) {
            alert('Ingrese un año válido (2020-2100)');
            return false;
        }
        return confirm('¿Crear el año ' + anio + ' y copiar todos los bienes del año actual?');
    }
    </script>
    </main>
    <footer style="background:linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);box-shadow:0 -6px 24px rgba(0,0,0,0.2);padding:30px 20px 20px 20px;margin-top:60px;text-align:center;border-top:4px solid #ff6f00;">
        <div style="max-width:1200px;margin:0 auto;">
            <div style="font-size:1.4em;font-weight:900;background:linear-gradient(135deg, #ff6f00 0%, #ff9800 50%, #ffc107 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:12px;letter-spacing:1px;">MAX SYSTEM</div>
            <div style="color:#e0e0e0;font-size:1.05em;margin-bottom:8px;font-weight:600;">Sistema de Inventario Inteligente</div>
            <div style="color:#b0b0b0;font-size:0.95em;margin-bottom:10px;">Colegio Fe y Alegría 44 &mdash; Gestión Multi-Anual</div>
            <div style="color:#888;font-size:0.9em;border-top:1px solid #444;padding-top:12px;margin-top:12px;">&copy; <?= date('Y') ?> Todos los derechos reservados | Desarrollado con <span style="color:#ff6f00;">❤</span> por Max System</div>
        </div>
    </footer>

    <!-- Modal de edición (diseño más claro y espaciado) -->
    <div id="modalEditar" class="modal-edit-overlay" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.55); z-index:1000; align-items:center; justify-content:center;">
        <style>
        .modal-edit-overlay .modal-content { background:#fff; padding:18px; border-radius:10px; max-width:720px; width:94vw; position:relative; box-shadow:0 8px 30px rgba(0,0,0,0.25); }
        .modal-edit-overlay .modal-content h3 { margin:0 0 8px 0; color:#b71c1c; }
        .modal-edit-overlay .modal-close { position:absolute; right:12px; top:12px; background:#fff;border:1px solid #ddd;border-radius:50%;width:34px;height:34px;cursor:pointer }
        .modal-edit-overlay form { display:block; max-height:65vh; overflow:auto; padding-right:6px }
        .modal-edit-overlay label { display:block; margin-bottom:10px; color:#333; font-weight:600; }
        .modal-edit-overlay input, .modal-edit-overlay select, .modal-edit-overlay textarea { width:100%; box-sizing:border-box; border:1px solid #ccc; padding:8px 10px; border-radius:6px; background:#fff; color:#222; }
        .modal-edit-overlay .two-col { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .modal-edit-overlay .three-col { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .modal-edit-overlay .actions { margin-top:12px; display:flex; gap:8px; justify-content:flex-end; }
        .modal-edit-overlay .actions .btn { background:#1976d2; border:none; padding:8px 14px; color:#fff; border-radius:8px; }
        .modal-edit-overlay .note { font-size:0.9em; color:#666; margin-top:8px; }
        .modal-edit-overlay input.invalid, .modal-edit-overlay select.invalid { border-color: #d32f2f; background:#fff7f7; box-shadow:0 0 0 3px rgba(211,47,47,0.06); }
        .modal-edit-overlay .invalid-msg { color:#d32f2f; font-size:0.9em; margin-top:6px; display:none; }
        </style>
        <div class="modal-content">
            <button class="modal-close" onclick="cerrarModal()" title="Cerrar">✕</button>
            <h3>Editar Inventario</h3>
            <form method="post" id="formEditar">
                <input type="hidden" name="id" id="edit_id">
                <div class="two-col">
                    <label>Nivel:
                        <select name="nivel" id="edit_nivel" required>
                            <option value="Inicial">Inicial</option>
                            <option value="Primaria">Primaria</option>
                            <option value="Secundaria">Secundaria</option>
                        </select>
                    </label>
                    <label>Aula Funcional:
                        <input name="aula_funcional" id="edit_aula_funcional" required>
                    </label>
                </div>
                <label>Denominación:
                    <input name="denominacion" id="edit_denominacion" required>
                </label>
                <div class="two-col">
                    <label>Marca:
                        <input name="marca" id="edit_marca">
                    </label>
                    <label>Modelo:
                        <input name="modelo" id="edit_modelo">
                    </label>
                </div>
                <div class="two-col">
                    <label>Tipo:
                        <select name="tipo" id="edit_tipo" required>
                            <option value="Mobiliario">Mobiliario</option>
                            <option value="Equipo">Equipo</option>
                            <option value="Material">Material</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </label>
                    <label>Color:
                        <input name="color" id="edit_color">
                    </label>
                </div>
                <div class="three-col">
                    <label>Serie: <input name="serie" id="edit_serie"></label>
                    <label>Cantidad: <input name="cantidad" id="edit_cantidad" type="number" min="1"></label>
                    <label>Estado:
                        <select name="estado" id="edit_estado" required>
                            <option value="Bueno">Bueno</option>
                            <option value="Regular">Regular</option>
                            <option value="Malo">Malo</option>
                        </select>
                    </label>
                </div>
                <div class="three-col">
                    <label>Largo (m): <input name="largo" id="edit_largo" type="number" step="0.01" placeholder="Ej: 1.20"></label>
                    <label>Ancho (m): <input name="ancho" id="edit_ancho" type="number" step="0.01" placeholder="Ej: 0.60"></label>
                    <label>Alto (m): <input name="alto" id="edit_alto" type="number" step="0.01" placeholder="Ej: 0.75"></label>
                </div>
                <div class="two-col">
                    <label>Documento de Alta: <input name="documento_alta" id="edit_documento_alta"></label>
                    <label>Fecha de Compra: <input name="fecha_compra" id="edit_fecha_compra" type="date"></label>
                </div>
                <div class="two-col">
                    <label>Número de Documento: <input name="numero_documento" id="edit_numero_documento"></label>
                    <label>Procedencia:
                        <select name="procedencia" id="edit_procedencia" required>
                            <option value="UGEL">UGEL</option>
                            <option value="Fe y Alegría">Fe y Alegría</option>
                            <option value="Jesuitas">Jesuitas</option>
                            <option value="Otra">Otra</option>
                        </select>
                    </label>
                </div>
                <label>Observaciones:
                    <input name="observaciones" id="edit_observaciones">
                </label>
                <div class="two-col">
                    <label>Usuario Responsable: <input name="usuario_responsable" id="edit_usuario_responsable"></label>
                    <label>Ubicación: <input name="ubicacion" id="edit_ubicacion"></label>
                </div>
                <label>Fecha Registro: <input name="fecha_registro" id="edit_fecha_registro" type="date"></label>
                <?php if($inventario_estado=='activo'): ?>
                <div class="actions"><button type="submit" name="actualizar" class="btn">Guardar Cambios</button></div>
                <?php else: ?>
                <div style="color:#b71c1c;font-weight:bold;">No se puede editar: año cerrado</div>
                <?php endif; ?>
                <div class="note">Consejo: usa Dry-run antes de importar grandes archivos para evitar duplicados.</div>
            </form>
        </div>
    </div>

    <script>
    // --- AJAX para cargar inventario dinámicamente ---
    function renderTablaInventario(datos) {
        if (!datos || !datos.length) {
            document.getElementById('contenedorTablaInventario').innerHTML = '<div style="color:#b71c1c;text-align:center;padding:30px 0;opacity:0.7;"><b>No hay resultados para los filtros seleccionados.</b></div>';
            return;
        }
        let html = `<div style='overflow-x:auto;width:100%;'>
            <div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;'>
                <div></div>
                <div><button id="btnDeleteSelected" class="btn" style="background:#c62828;color:#fff;padding:8px 12px;border-radius:8px;">Eliminar seleccionados</button></div>
            </div>
            <table style='width:1500px;min-width:1000px;max-width:none;table-layout:auto;'><thead><tr>
            <th style="width:36px;text-align:center"><input type='checkbox' id='select_all_rows' /></th>
            <th>Nivel</th><th>Aula</th><th>Cantidad</th><th>Denominación</th><th>Marca</th><th>Modelo</th><th>Tipo</th><th>Color</th><th>Serie</th><th>Doc. Alta</th><th>Fecha Compra</th><th>N° Doc</th><th>Estado</th><th>Procedencia</th><th>Obs.</th><th>Usuario</th><th>Ubicación</th><th>Fecha Registro</th><th>Acciones</th>
        </tr></thead><tbody>`;
        datos.forEach(row => {
            const puedeEditar = <?= $inventario_estado=='activo'?'true':'false' ?>;
                        html += `<tr>
                                <td style='text-align:center;'><input type='checkbox' class='select_row' data-id='${row.id}' /></td>
                                <td>${row.nivel||''}</td>
                                <td>${row.aula_funcional||''}</td>
                                <td>${getRowCantidad(row) || ''}</td>
                                <td>${row.denominacion||''}</td>
                                <td>${row.marca||''}</td>
                                <td>${row.modelo||''}</td>
                                <td>${row.tipo||''}</td>
                                <td>${row.color||''}</td>
                                <td>${row.serie||''}</td>
                                <td>${row.documento_alta||''}</td>
                                <td>${row.fecha_compra||''}</td>
                                <td>${row.numero_documento||''}</td>
                                <td>${row.estado||''}</td>
                                <td>${row.procedencia||''}</td>
                                <td>${row.observaciones||''}</td>
                                <td>${row.usuario_responsable||''}</td>
                                <td>${row.ubicacion||''}</td>
                                <td>${row.fecha_registro||''}</td>
                                <td style='min-width:120px;'>
                                    <div style='display:flex;gap:6px;justify-content:center;align-items:center;'>`;
            if (puedeEditar) {
                html += `<button class='btn' style='padding:3px 10px;font-size:0.95em;background:#1976d2;' onclick='editarFila(${row.id})'>Editar</button>
                    <button class='btn btn-delete-single' data-id='${row.id}' style='padding:3px 10px;font-size:0.95em;background:#c62828;'>Eliminar</button>`;
            } else {
                html += `<span style='color:#888;font-size:0.9em;'>Solo lectura</span>`;
            }
            html += `</div></td></tr>`;
        });
        html += `</tbody></table></div><div style='margin:18px 0;font-weight:bold;text-align:right;'>Total de registros: ${datos.length}</div>`;
        document.getElementById('contenedorTablaInventario').innerHTML = html;
        // Hook: seleccionar todo
        const selAll = document.getElementById('select_all_rows');
        if (selAll) {
            selAll.addEventListener('change', function(){
                const checked = !!this.checked;
                document.querySelectorAll('.select_row').forEach(cb=>{ cb.checked = checked; });
            });
        }
        // Botón eliminar seleccionados
        const btnDel = document.getElementById('btnDeleteSelected');
        if (btnDel) {
            btnDel.addEventListener('click', function(){
                const selected = Array.from(document.querySelectorAll('.select_row:checked')).map(cb=>cb.getAttribute('data-id'));
                if (!selected.length) { alert('Selecciona al menos un registro para eliminar.'); return; }
                // Construir resumen por aula/nivel desde window.lastInventarioData
                const byAula = {}; const byNivel = {};
                selected.forEach(id => {
                    const item = (window.lastInventarioData||[]).find(r=>String(r.id)===String(id));
                    if (!item) return;
                    if (item.aula_funcional) byAula[item.aula_funcional] = (byAula[item.aula_funcional]||0)+1;
                    if (item.nivel) byNivel[item.nivel] = (byNivel[item.nivel]||0)+1;
                });
                let summary = 'Se eliminarán ' + selected.length + ' registros.\n\nResumen por aula:\n';
                Object.entries(byAula).forEach(([k,v])=>{ summary += `${k}: ${v}\n`; });
                summary += '\nResumen por nivel:\n';
                Object.entries(byNivel).forEach(([k,v])=>{ summary += `${k}: ${v}\n`; });
                if (!confirm(summary + '\n¿Confirmar y mover a papelera (undo disponible)?')) return;
                // Enviar petición AJAX para mover a trash
                const fd = new FormData(); fd.append('action','move_to_trash');
                selected.forEach(id=> fd.append('ids[]', id));
                fetch('inventario_central.php', { method:'POST', body: fd })
                    .then(r=>r.json())
                    .then(resp=>{
                        if (!resp.ok) { alert('Error: ' + (resp.message||'respuesta inválida')); return; }
                        // Eliminar filas del DOM y actualizar datos
                        selected.forEach(id=>{
                            const cb = document.querySelector(".select_row[data-id='"+id+"']");
                            if (cb) cb.closest('tr').remove();
                            if (window.lastInventarioData) window.lastInventarioData = window.lastInventarioData.filter(r=>String(r.id)!==String(id));
                        });
                        // Mostrar notificación persistente con opción Deshacer usando action_id devuelto por el servidor
                        const actionId = resp.action_id || null;
                        if (actionId) {
                            showUndoNotification(actionId, selected.length);
                        } else {
                            alert('Registros movidos a papelera. (Sin action_id devuelto)');
                        }
                    })
                    .catch(err => { console.error(err); alert('Error al mover a papelera.'); });
            });
        }
        // Guardar los datos mostrados para usos posteriores (impresión compacta)
        try { window.lastInventarioData = datos || []; } catch(e) { window.lastInventarioData = datos || []; }
        // Attach handler for per-row delete buttons (AJAX)
        document.querySelectorAll('.btn-delete-single').forEach(btn => {
            btn.addEventListener('click', function(){
                const id = this.getAttribute('data-id');
                if (!id) return;
                const item = (window.lastInventarioData||[]).find(r=>String(r.id)===String(id));
                const aula = item ? item.aula_funcional : '';
                const nivel = item ? item.nivel : '';
                if (!confirm(`Eliminar registro ID ${id} - Nivel: ${nivel} Aula: ${aula}? Esta acción moverá el registro a la papelera.`)) return;
                const fd = new FormData(); fd.append('action','move_to_trash'); fd.append('ids[]', id);
                fetch('inventario_central.php',{method:'POST', body:fd}).then(r=>r.json()).then(resp=>{
                    if (!resp.ok) { alert('Error al eliminar'); return; }
                    // remover fila
                    const tr = btn.closest('tr'); if (tr) tr.remove();
                    if (window.lastInventarioData) window.lastInventarioData = window.lastInventarioData.filter(r=>String(r.id)!==String(id));
                    const actionId = resp.action_id || null;
                    if (actionId) {
                        showUndoNotification(actionId, 1);
                    } else {
                        alert('Registro movido a papelera.');
                    }
                }).catch(e=>{ console.error(e); alert('Error al eliminar'); });
            });
        });
    }

    // --- Undo helpers: mostrar notificación persistente y ejecutar undo_by_action ---
    function undoByAction(actionId, callbacks) {
        callbacks = callbacks || {};
        if (!actionId) { if (callbacks.error) callbacks.error('actionId inválido'); return; }
        const fd = new FormData(); fd.append('action','undo_by_action'); fd.append('action_id', actionId);
        fetch('inventario_central.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(resp => {
                if (!resp || !resp.ok) {
                    if (callbacks.error) callbacks.error(resp && resp.message ? resp.message : 'Error desconocido');
                    return;
                }
                if (callbacks.success) callbacks.success(resp);
            })
            .catch(err => { if (callbacks.error) callbacks.error(err.message || String(err)); });
    }

    function showUndoNotification(actionId, count) {
        const id = 'undo_notif_' + String(actionId);
        if (document.getElementById(id)) return;
        const cont = document.createElement('div'); cont.id = id;
        cont.style.position = 'fixed'; cont.style.right = '20px'; cont.style.top = '80px'; cont.style.zIndex = 2000;
        cont.style.background = '#fff'; cont.style.border = '1px solid #ddd'; cont.style.padding = '10px 12px'; cont.style.borderRadius = '8px'; cont.style.boxShadow = '0 6px 20px rgba(0,0,0,0.12)'; cont.style.fontSize = '0.95rem';
        cont.style.display = 'flex'; cont.style.alignItems = 'center'; cont.style.gap = '10px';
        const msg = document.createElement('div'); msg.innerHTML = `<b>${count}</b> registro(s) movido(s) a papelera.`;
        const btn = document.createElement('button'); btn.className = 'btn'; btn.textContent = 'Deshacer'; btn.style.background = '#1976d2'; btn.style.color = '#fff'; btn.style.padding = '6px 10px'; btn.style.borderRadius='6px';
        const close = document.createElement('button'); close.textContent = '×'; close.title = 'Cerrar'; close.style.background='transparent'; close.style.border='none'; close.style.fontSize='16px'; close.style.cursor='pointer';
        cont.appendChild(msg); cont.appendChild(btn); cont.appendChild(close);
        document.body.appendChild(cont);
        btn.addEventListener('click', function(){
            btn.disabled = true; btn.textContent = 'Restaurando...';
            undoByAction(actionId, {
                success: function(resp){
                    alert('Deshacer completado. Registros restaurados.');
                    try { loadInventarioPage(1); } catch(e){ location.reload(); }
                    if (cont && cont.parentNode) cont.parentNode.removeChild(cont);
                },
                error: function(errMsg){
                    alert('Error al deshacer: ' + errMsg);
                    btn.disabled = false; btn.textContent = 'Deshacer';
                }
            });
        });
        close.addEventListener('click', function(){ if (cont && cont.parentNode) cont.parentNode.removeChild(cont); });
        setTimeout(function(){ if (document.getElementById(id)) { try { document.getElementById(id).remove(); } catch(e){} } }, 20000);
    }

    // Definir variable global de inventario_id para JS
    var inventarioIdGlobal = <?= (int)$inventario_id ?>;

    // Imprimir sólo el inventario mostrado: abrir ventana nueva con la tabla y lanzar print
    // Construir tabla imprimible compacta a partir de datos (más adaptable)
    function buildPrintableTable(data, opts) {
        opts = opts || {};
        // Ordenar datos alfabéticamente por denominación antes de construir la tabla
        data = data.slice().sort((a, b) => {
            const denA = (a.denominacion || '').toString().toLowerCase();
            const denB = (b.denominacion || '').toString().toLowerCase();
            return denA.localeCompare(denB);
        });
        // Columnas por defecto en orden (puedes ocultar algunas poniéndolas en opts.hide)
        const columns = [
            {k:'nivel', label:'Nivel'},
            {k:'aula_funcional', label:'Aula'},
            {k:'cantidad', label:'Cant.'},
            {k:'denominacion', label:'Denominación'},
            {k:'marca', label:'Marca'},
            {k:'modelo', label:'Modelo'},
            {k:'tipo', label:'Tipo'},
            {k:'serie', label:'Serie'},
            {k:'estado', label:'Estado'},
            {k:'usuario_responsable', label:'Usuario'},
            {k:'ubicacion', label:'Ubicación'},
            {k:'fecha_registro', label:'Fecha Registro'}
        ];
        // Por defecto ocultar las columnas de medidas para impresión
        const defaultHide = ['largo','ancho','alto'];
        const hide = (opts.hide || defaultHide).map(h=>h.toString());
        const table = document.createElement('table');
        table.style.width = '100%';
        table.style.borderCollapse = 'collapse';
        // thead
        const thead = document.createElement('thead');
        const trh = document.createElement('tr');
        columns.forEach(col => {
            if (hide.indexOf(col.k) !== -1) return;
            const th = document.createElement('th'); th.textContent = col.label;
            th.style.border = '1px solid #ccc'; th.style.padding = '4px'; th.style.fontSize = '11px'; th.style.background = '#f2f2f2';
            trh.appendChild(th);
        });
        thead.appendChild(trh);
        table.appendChild(thead);
        // tbody
        const tbody = document.createElement('tbody');
        data.forEach(row => {
            const tr = document.createElement('tr');
            columns.forEach(col => {
                if (hide.indexOf(col.k) !== -1) return;
                const td = document.createElement('td');
                let v = row[col.k] || '';
                // Normalizar cantidad: si no existe en la fila intentar extraerla desde observaciones
                if (col.k === 'cantidad') {
                    if (v === undefined || v === null || v === '') {
                        const parsed = extractCantidadFromObservaciones(row['observaciones'] || '');
                        // Si no se encuentra cantidad en la fila o en observaciones,
                        // dejar vacío en la impresión en lugar de forzar `1`.
                        v = (parsed !== null) ? parsed : '';
                    }
                }
                td.textContent = v;
                td.style.border = '1px solid #ccc'; td.style.padding = '4px'; td.style.fontSize = '11px'; td.style.whiteSpace = 'normal';
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    // Función que descarga todas las páginas del inventario (según filtros) y devuelve un array con todos los registros
    async function fetchAllInventarioData(opts) {
        opts = opts || {};
        const nivel = opts.nivel || document.getElementById('filtro_nivel').value || '';
        const aula = opts.aula || document.getElementById('filtro_aula').value || '';
        const denominacion = opts.denominacion || document.getElementById('filtro_denominacion').value || '';
        const per_page = 500; // maximum enforced by server
        let page = 1;
        let all = [];
        const maxPages = 50; // Límite de seguridad para evitar loops infinitos
        
        while (page <= maxPages) {
            let url = 'inventario_central.php?ajax=1&page='+encodeURIComponent(page)+'&per_page='+encodeURIComponent(per_page);
            if (nivel) url += '&nivel=' + encodeURIComponent(nivel);
            if (aula) url += '&aula=' + encodeURIComponent(aula);
            if (denominacion) url += '&denominacion=' + encodeURIComponent(denominacion);
            
            try {
                const resp = await fetch(url);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const json = await resp.json();
                const data = json.data || [];
                all = all.concat(data);
                const total = json.total || 0;
                const pages = Math.max(1, Math.ceil(total / per_page));
                
                // Si no hay más datos o llegamos a la última página, salir
                if (data.length === 0 || page >= pages) break;
                
                page++;
            } catch(err) {
                console.error('Error en página ' + page, err);
                throw err;
            }
        }
        return all;
    }

    document.getElementById('btnImprimir').addEventListener('click', async function(){
        const cont = document.getElementById('contenedorTablaInventario');
        if (!cont || !cont.innerHTML.trim()) { alert('No hay inventario para imprimir. Primero haga clic en "Mostrar Inventario Total".'); return; }
        // Mostrar indicador mientras se descargan todas las páginas
        const originalHtml = cont.innerHTML;
        cont.innerHTML = '<div style="text-align:center;padding:20px;color:#b71c1c;font-weight:700">Preparando impresión: cargando todos los registros... por favor espere</div>';
        let dataForPrint = null;
        try {
            dataForPrint = await fetchAllInventarioData();
        } catch (err) {
            console.error('Error cargando todas las páginas para impresión', err);
            alert('No se pudo cargar todos los registros para imprimir. Se usará lo visible en pantalla. Error: ' + err.message);
            dataForPrint = (window.lastInventarioData && window.lastInventarioData.length) ? window.lastInventarioData : null;
        }
        // Restaurar contenedor
        cont.innerHTML = originalHtml;
        const selectedYearEl = document.querySelector('select[name="anio"]');
        const selectedYear = selectedYearEl ? selectedYearEl.value : '';
        const nivelVal = document.getElementById('filtro_nivel').value || '';
        const aulaVal = document.getElementById('filtro_aula').value || '';
        const denomVal = document.getElementById('filtro_denominacion').value || '';
        const filtrosParts = [];
        filtrosParts.push('<div class="item"><b>Nivel:</b> ' + (nivelVal ? nivelVal : 'Todos') + '</div>');
        filtrosParts.push('<div class="item"><b>Aula:</b> ' + (aulaVal ? aulaVal : 'Todas') + '</div>');
        filtrosParts.push('<div class="item"><b>Denominación:</b> ' + (denomVal ? denomVal : 'Todas') + '</div>');
        const filtersHtml = `<div class="print-filters">${filtrosParts.join('')}</div>`;

        const styles = `
            <style>
                @page { size: A4 landscape; margin: 8mm; }
                body{font-family:Arial,Helvetica,sans-serif;margin:8px;color:#222}
                .print-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
                .print-header h1{margin:0;font-size:18px;color:#b71c1c}
                .print-header .year{font-size:14px;color:#333}
                .print-filters{font-size:12px;color:#333;margin-bottom:8px;display:flex;gap:8px;flex-wrap:wrap}
                .print-filters .item{background:#f7f7f7;padding:5px 8px;border-radius:6px;border:1px solid #eee}
                table{border-collapse:collapse;width:100%;table-layout:fixed;word-break:break-word}
                th,td{border:1px solid #ccc;padding:4px;font-size:10px}
                th{background:#f2f2f2}
                td{white-space:normal}
                thead { display: table-header-group; }
                tfoot { display: table-footer-group; }
                .print-detailed-summary { page-break-inside: avoid; margin-top:12px }
            </style>
        `;

        const w = window.open('','_blank');
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Inventario - Impresión</title>'+styles+'</head><body>');
        const headerHtml = `<div class="print-header"><h1>Inventario Fe y Alegría 44</h1><div class="year">Año: ${selectedYear}</div></div>` + filtersHtml + '<hr/>';
        w.document.write(headerHtml);

        if (dataForPrint) {
            // Construir tabla compacta desde datos (ocultar la columna acciones)
            try {
                const table = buildPrintableTable(dataForPrint, { hide: [] });
                // Calcular resumen por aula
                const headersArr = Array.from(table.querySelectorAll('thead th'));
                let aulaIndex = -1;
                headersArr.forEach((th, idx) => { const t = (th.textContent||'').trim().toLowerCase(); if (t==='aula' || t.includes('aula')) aulaIndex = idx; });
                const aulaCounts = {};
                Array.from(table.querySelectorAll('tbody tr')).forEach(r => {
                    const tds = r.querySelectorAll('td');
                    if (tds && tds.length > aulaIndex) {
                        const a = (tds[aulaIndex].innerText || '').trim() || 'Sin aula'; aulaCounts[a] = (aulaCounts[a]||0)+1;
                    }
                });
                // Añadir tfoot resumen compacto
                const tfoot = document.createElement('tfoot');
                const trFoot = document.createElement('tr');
                const tdFoot = document.createElement('td'); tdFoot.colSpan = headersArr.length || 1;
                // Ordenar aulas por cantidad descendente
                const sortedAulas = Object.entries(aulaCounts).sort((a,b)=>b[1]-a[1]);
                const parts = sortedAulas.map(([k,v]) => k + ': ' + v);
                tdFoot.innerHTML = '<b>Resumen por aula</b>: ' + (parts.length ? parts.join(' — ') : 'Ninguno');
                trFoot.appendChild(tdFoot); tfoot.appendChild(trFoot); table.appendChild(tfoot);
                w.document.write(table.outerHTML);

                // Detalle final
                let detailedHtml = '<div class="print-detailed-summary"><h4 style="margin-top:18px;color:#b71c1c;">Resumen por aula (detallado)</h4>';
                if (parts.length) { detailedHtml += '<ul style="font-size:12px;line-height:1.4;margin:6px 0 0 18px;">'; parts.forEach(p=>{ detailedHtml += '<li>'+p+'</li>'; }); detailedHtml += '</ul>'; }
                else detailedHtml += '<div style="color:#666">No hay datos para resumir.</div>';
                detailedHtml += '</div>';
                w.document.write(detailedHtml);
            } catch(err) {
                console.error(err);
                w.document.write('<div style="color:#b71c1c">Error generando tabla de impresión desde datos.</div>');
            }
        } else {
            // Fallback: clonar la tabla visible y limpiar botones
            const origTable = cont.querySelector('table');
            const table = origTable.cloneNode(true);
            // Eliminar columnas de medidas (largo/ancho/alto) si existen en el encabezado
            try {
                const ths = table.querySelectorAll('thead th');
                const removeIndexes = [];
                ths.forEach((th, idx) => {
                    const t = (th.textContent||'').trim().toLowerCase();
                    if (t.includes('largo') || t.includes('ancho') || t.includes('alto')) removeIndexes.push(idx);
                });
                // eliminar de derecha a izquierda para no desalinear índices
                removeIndexes.sort((a,b)=>b-a).forEach(idx=>{
                    // eliminar TH
                    const th = table.querySelectorAll('thead th')[idx]; if (th) th.parentNode.removeChild(th);
                    // eliminar TD en cada fila
                    table.querySelectorAll('tbody tr').forEach(r=>{ const tds = r.querySelectorAll('td'); if (tds && tds.length>idx) tds[idx].parentNode.removeChild(tds[idx]); });
                });
            } catch(e) { /* no crítico */ }
            table.querySelectorAll('button, input, form, a').forEach(el=>{ const txt = el.innerText||el.value||''; const span=document.createElement('span'); span.textContent=txt; el.parentNode.replaceChild(span, el); });
            w.document.write(table.outerHTML);
        }

        w.document.write('</body></html>');
        w.document.close();
        setTimeout(()=>{ w.focus(); w.print(); }, 400);
    });

    // Actualizar renderTablaInventario para usar paginación via AJAX
    // Sobrescribimos la función btnMostrarInventario click para pedir página 1 por defecto
    document.getElementById('btnMostrarInventario').addEventListener('click', function(){
        loadInventarioPage(1);
    });

    // Función para cargar una página del inventario con filtros aplicados
    function loadInventarioPage(page) {
        const nivel = document.getElementById('filtro_nivel').value;
        const aula = document.getElementById('filtro_aula').value;
        const denominacion = document.getElementById('filtro_denominacion').value;
        // Usar la variable global inventarioIdGlobal en lugar de buscar en el DOM
        const inventarioId = inventarioIdGlobal;
        
        document.getElementById('contenedorTablaInventario').innerHTML = '<div style="color:#b71c1c;text-align:center;padding:30px 0;opacity:0.7;"><b>Cargando inventario...</b></div>';
        
        let url = 'inventario_central.php?ajax=1&page='+encodeURIComponent(page)+'&per_page=100';
        if (nivel) url += '&nivel=' + encodeURIComponent(nivel);
        if (aula) url += '&aula=' + encodeURIComponent(aula);
        if (denominacion) url += '&denominacion=' + encodeURIComponent(denominacion);
        if (inventarioId) url += '&inventario_id=' + encodeURIComponent(inventarioId);
        
        // Agregar timeout de 30 segundos
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);
        
        fetch(url, { signal: controller.signal })
            .then(r => { 
                clearTimeout(timeoutId);
                if(!r.ok) throw new Error('HTTP ' + r.status); 
                return r.json(); 
            })
            .then(result => {
                // result: {data:[], total:, page:, per_page:}
                renderTablaInventario(result.data || []);
                renderPagination(result.total || 0, result.page || 1, result.per_page || 100);
            })
            .catch(err => { 
                clearTimeout(timeoutId);
                console.error('Error al cargar inventario', err); 
                let errorMsg = 'Error al cargar inventario.';
                if (err.name === 'AbortError') {
                    errorMsg = 'La petición tardó demasiado tiempo. Por favor, intenta de nuevo.';
                } else {
                    errorMsg += ' ' + (err.message || '');
                }
                document.getElementById('contenedorTablaInventario').innerHTML = '<div style="color:#b71c1c;text-align:center;padding:30px 0;"><b>' + errorMsg + '</b><br><button class="btn" onclick="loadInventarioPage(1)" style="margin-top:15px;">Reintentar</button></div>'; 
            });
    }

    function renderPagination(total, page, per_page) {
        const container = document.getElementById('contenedorTablaInventario');
        const pages = Math.ceil(total / per_page);
        let html = '';
        if (pages <= 1) return; // nada que mostrar
        html += '<div style="display:flex;justify-content:center;gap:8px;margin:12px 0;">';
        if (page > 1) html += `<button class="btn" onclick="(function(){ loadInventarioPage(${page-1}); })()">Anterior</button>`;
        for (let p=1; p<=pages; p++) {
            if (p === page) html += `<button class="btn" style="background:#b71c1c;color:#fff">${p}</button>`;
            else if (p <= 3 || p > pages-3 || (p>=page-2 && p<=page+2)) html += `<button class="btn" onclick="(function(){ loadInventarioPage(${p}); })()">${p}</button>`;
            else if (p===4 || p===pages-3) html += `<span style="padding:8px 10px;color:#666">...</span>`;
        }
        if (page < pages) html += `<button class="btn" onclick="(function(){ loadInventarioPage(${page+1}); })()">Siguiente</button>`;
        html += '</div>';
        // insertar al final del contenedor (si existe una tabla)
        const existing = container.querySelector('.pagination-controls');
        const div = document.createElement('div'); div.className='pagination-controls'; div.innerHTML = html;
        // eliminar controls anteriores
        const old = container.querySelectorAll('.pagination-controls'); old.forEach(o=>o.remove());
        container.appendChild(div);
    }

    // --- Filtro de aulas dependiente del nivel ---
    const aulasPorNivel = <?php echo json_encode($aulas, JSON_UNESCAPED_UNICODE); ?>;
    function actualizarAulasFiltro() {
        const nivel = document.getElementById('filtro_nivel').value;
        const selectAula = document.getElementById('filtro_aula');
        selectAula.innerHTML = '<option value="">Todas</option>';
        if (nivel && aulasPorNivel[nivel]) {
            aulasPorNivel[nivel].forEach(aula => {
                const opt = document.createElement('option');
                opt.value = aula;
                opt.textContent = aula;
                selectAula.appendChild(opt);
            });
        } else {
            // Si no hay nivel, mostrar todas las aulas únicas
            let todas = [];
            Object.values(aulasPorNivel).forEach(arr => todas = todas.concat(arr));
            [...new Set(todas)].forEach(aula => {
                const opt = document.createElement('option');
                opt.value = aula;
                opt.textContent = aula;
                selectAula.appendChild(opt);
            });
        }
    }
    document.getElementById('filtro_nivel').addEventListener('change', actualizarAulasFiltro);
    // Inicializar aulas al cargar
    window.addEventListener('DOMContentLoaded', actualizarAulasFiltro);

    // Modal edición (se mantiene igual)
    let inventario = [];
    function editarFila(id) {
        // Buscar el registro en la tabla cargada
        fetch('inventario_central.php?ajax=1&id='+id)
            .then(r=>r.json())
            .then(datos=>{
                if (!datos || !datos.length) return;
                const fila = datos[0];
                // Asignar valores solo si el elemento existe en el DOM
                const setIfExists = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
                setIfExists('edit_id', fila.id);
                setIfExists('edit_nivel', fila.nivel);
                setIfExists('edit_aula_funcional', fila.aula_funcional);
                setIfExists('edit_denominacion', fila.denominacion);
                setIfExists('edit_marca', fila.marca);
                setIfExists('edit_modelo', fila.modelo);
                setIfExists('edit_tipo', fila.tipo);
                setIfExists('edit_color', fila.color);
                setIfExists('edit_serie', fila.serie);
                setIfExists('edit_largo', fila.largo);
                setIfExists('edit_ancho', fila.ancho);
                setIfExists('edit_alto', fila.alto);
                setIfExists('edit_documento_alta', fila.documento_alta);
                setIfExists('edit_fecha_compra', fila.fecha_compra);
                setIfExists('edit_cantidad', fila.cantidad);
                setIfExists('edit_numero_documento', fila.numero_documento);
                setIfExists('edit_estado', fila.estado);
                setIfExists('edit_procedencia', fila.procedencia);
                setIfExists('edit_observaciones', fila.observaciones);
                setIfExists('edit_usuario_responsable', fila.usuario_responsable);
                setIfExists('edit_ubicacion', fila.ubicacion);
                setIfExists('edit_fecha_registro', fila.fecha_registro);
                document.getElementById('modalEditar').style.display = 'flex';
            });
    }
    function cerrarModal() {
        document.getElementById('modalEditar').style.display = 'none';
    }

    // Validación simple del formulario de edición: marcar campos requeridos
    (function(){
        const form = document.getElementById('formEditar');
        if (!form) return;
        const requiredIds = ['edit_nivel','edit_aula_funcional','edit_denominacion','edit_tipo','edit_estado','edit_procedencia'];
        form.addEventListener('submit', function(e){
            let invalids = [];
            requiredIds.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const v = (el.value || '').toString().trim();
                if (v === '') {
                    el.classList.add('invalid');
                    // mostrar mensaje debajo del label
                    const lbl = el.closest('label');
                    if (lbl && !lbl.querySelector('.invalid-msg')) {
                        const msg = document.createElement('div'); msg.className = 'invalid-msg'; msg.textContent = 'Campo requerido'; lbl.appendChild(msg);
                    }
                    invalids.push(el);
                } else {
                    el.classList.remove('invalid');
                    const lbl = el.closest('label'); if (lbl) { const m = lbl.querySelector('.invalid-msg'); if (m) m.style.display='none'; }
                }
            });
            if (invalids.length) {
                e.preventDefault();
                invalids[0].focus();
                return false;
            }
        });
        // quitar mark al escribir
        Array.from(form.querySelectorAll('input,select,textarea')).forEach(el=>{
            el.addEventListener('input', function(){ el.classList.remove('invalid'); const lbl = el.closest('label'); if (lbl) { const m = lbl.querySelector('.invalid-msg'); if (m) m.style.display='none'; } });
        });
        // Atajos de teclado: Esc=cerrar, Enter=enviar (si no está en textarea)
        document.addEventListener('keydown', function(ev){
            const modal = document.getElementById('modalEditar');
            if (!modal || modal.style.display === 'none') return;
            if (ev.key === 'Escape') { ev.preventDefault(); cerrarModal(); }
            if (ev.key === 'Enter') {
                const active = document.activeElement;
                if (active && active.tagName !== 'TEXTAREA' && !ev.shiftKey && !ev.ctrlKey && !ev.metaKey) {
                    ev.preventDefault();
                    if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
                }
            }
        });
    })();
    </script>
</body>
</html>



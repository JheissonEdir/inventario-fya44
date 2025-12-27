<?php
// Página Papelera: listar inventario_trash, restaurar y eliminar permanentemente
@ini_set('display_errors', 0);
session_start();
if (empty($_SESSION['admin'])) { header('Location: login_central.php'); exit; }
$host = 'localhost'; $user='root'; $pass=''; $db='inventario_escolar';
$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) die('Error de conexión: '.$conn->connect_error);

// Crear tabla inventario_trash si no existe (suavemente)
function table_exists_local($conn,$table){ $table = $conn->real_escape_string($table); $res = $conn->query("SHOW TABLES LIKE '$table'"); return ($res && $res->num_rows>0); }
if (!table_exists_local($conn,'inventario_trash')) {
    $conn->query("CREATE TABLE IF NOT EXISTS inventario_trash LIKE inventario");
    if ($conn->query("SHOW COLUMNS FROM inventario_trash LIKE 'deleted_at'") && $conn->affected_rows==0) {
        // agregar deleted_at
        @$conn->query("ALTER TABLE inventario_trash ADD COLUMN deleted_at DATETIME NULL");
    }
}

// Procesar acciones POST (restore / delete)
$mensaje = '';
$is_ajax = (!empty($_POST['ajax']) || !empty($_GET['ajax']));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resp = ['ok'=>false,'message'=>'Nada hecho'];
    if (!empty($_POST['restore_ids']) && is_array($_POST['restore_ids'])) {
        $ids = array_map('intval', $_POST['restore_ids']); $ids = array_filter($ids,function($v){return $v>0;});
        if ($ids) {
            $ids_list = implode(',',$ids);
            $conn->begin_transaction();
            $conn->query("INSERT INTO inventario SELECT * FROM inventario_trash WHERE id IN ($ids_list)");
            $restored = $conn->affected_rows;
            $conn->query("DELETE FROM inventario_trash WHERE id IN ($ids_list)");
            $deleted = $conn->affected_rows;
            $conn->commit();
            $mensaje = "Restaurados: $restored. Eliminados de papelera: $deleted.";
            $resp = ['ok'=>true,'restored'=>intval($restored),'deleted_from_trash'=>intval($deleted),'message'=>$mensaje];
        }
    }
    if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
        $ids = array_map('intval', $_POST['delete_ids']); $ids = array_filter($ids,function($v){return $v>0;});
        if ($ids) {
            $ids_list = implode(',',$ids);
            $conn->query("DELETE FROM inventario_trash WHERE id IN ($ids_list)");
            $deleted = $conn->affected_rows;
            $mensaje = "Eliminados permanentemente: $deleted.";
            $resp = ['ok'=>true,'deleted_permanently'=>intval($deleted),'message'=>$mensaje];
        }
    }
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }
}

// Manejar restauración por action_id (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['restore_by_action_id'])) {
    $aid = intval($_POST['restore_by_action_id']);
    $resp = ['ok'=>false,'message'=>'Acción no encontrada'];
    if ($aid > 0) {
        // Buscar la acción en trash_actions
        $resAct = $conn->query("SELECT * FROM trash_actions WHERE id=$aid LIMIT 1");
        if ($resAct && $resAct->num_rows>0) {
            $act = $resAct->fetch_assoc();
            $ids = json_decode($act['ids_json'], true);
            if (is_array($ids) && !empty($ids)) {
                $ids = array_map('intval', $ids); $ids = array_filter($ids,function($v){return $v>0;});
                if (!empty($ids)) {
                    $ids_list = implode(',', $ids);
                    $conn->begin_transaction();
                    $conn->query("INSERT INTO inventario SELECT * FROM inventario_trash WHERE id IN ($ids_list)");
                    $restored = $conn->affected_rows;
                    $conn->query("DELETE FROM inventario_trash WHERE id IN ($ids_list)");
                    $deleted = $conn->affected_rows;
                    $conn->commit();
                    $resp = ['ok'=>true,'restored'=>intval($restored),'deleted_from_trash'=>intval($deleted),'message'=>'Restauración por action completada.'];
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); $conn->close(); exit;
}

// Paginación y listado
$page = isset($_GET['page'])? max(1,intval($_GET['page'])):1; $per_page = 50; $offset = ($page-1)*$per_page;
$totalRes = $conn->query("SELECT COUNT(*) AS cnt FROM inventario_trash"); $total = ($totalRes && ($r=$totalRes->fetch_assoc()))?intval($r['cnt']):0;
$result = $conn->query("SELECT * FROM inventario_trash ORDER BY deleted_at DESC LIMIT $per_page OFFSET $offset");
$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;

// Cargar últimas acciones de trash_actions (audit)
$actions = [];
if ($conn->query("SHOW TABLES LIKE 'trash_actions'") && $conn->affected_rows>=0) {
    $resActs = $conn->query("SELECT * FROM trash_actions ORDER BY ts DESC LIMIT 50");
    while ($a = $resActs->fetch_assoc()) $actions[] = $a;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Papelera - Inventario</title>
<style>body{font-family:Arial;margin:18px;background:#f7f7f7}table{border-collapse:collapse;width:100%;background:#fff}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f1f1f1;color:#333}.btn{background:#b71c1c;color:#fff;padding:6px 10px;border-radius:6px;text-decoration:none;border:none;cursor:pointer} .muted{color:#666;font-size:0.9em}</style>
</head>
<body>
    <h2>Papelera de Inventario</h2>
    <p><a class="btn" href="inventario_central.php">Volver al panel</a></p>
    <?php if($mensaje): ?><div style="padding:10px;background:#e8ffe8;border:1px solid #a6e6a6;margin-bottom:10px;"><?=htmlspecialchars($mensaje)?></div><?php endif; ?>
    <form method="post" id="formTrash">
        <div style="overflow:auto;max-height:540px;border:1px solid #eee;background:#fff;padding:6px;border-radius:8px;">
        <table>
            <thead><tr><th style="width:36px;text-align:center"><input type="checkbox" id="sel_all_trash"></th><th>Id</th><th>Nivel</th><th>Aula</th><th>Denominación</th><th>Usuario</th><th>Deleted At</th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:18px;color:#666">No hay elementos en la papelera.</td></tr>
            <?php else: foreach($rows as $r): ?>
                <tr>
                    <td style="text-align:center"><input type="checkbox" name="restore_ids[]" value="<?= $r['id'] ?>"></td>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['nivel'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['aula_funcional'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['denominacion'] ?? '') ?></td>
                    <td class="muted"><?= htmlspecialchars($r['usuario_responsable'] ?? '') ?></td>
                    <td class="muted"><?= htmlspecialchars($r['deleted_at'] ?? '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn" onclick="document.getElementById('formTrash').action='';">Restaurar seleccionados</button>
            <button type="button" id="btnDeletePermanent" class="btn" style="background:#333">Eliminar permanentemente</button>
            <button type="button" id="btnRestoreByAction" class="btn" style="background:#1976d2">Restaurar por action_id</button>
        </div>
    </form>
    <div style="margin-top:18px">
        <h3>Acciones recientes (audit)</h3>
        <?php if (empty($actions)): ?><div class="muted">No hay acciones registradas.</div><?php else: ?>
            <ul>
            <?php foreach($actions as $a): ?>
                <li><b><?=htmlspecialchars($a['action_type'])?></b> — <?=htmlspecialchars($a['user_actor'] ?? '')?> — <?=htmlspecialchars($a['ts'])?> — ids: <?=htmlspecialchars($a['ids_json'])?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <script>
        document.getElementById('sel_all_trash').addEventListener('change', function(){ const c=this.checked; document.querySelectorAll('input[name="restore_ids[]"]').forEach(cb=>cb.checked=c); });
        document.getElementById('btnDeletePermanent').addEventListener('click', function(){
            const selected = Array.from(document.querySelectorAll('input[name="restore_ids[]"]:checked')).map(i=>i.value);
            if (!selected.length) { alert('Selecciona al menos uno'); return; }
            if (!confirm('Eliminar permanentemente ' + selected.length + ' registros? Esta acción no se puede deshacer.')) return;
            // enviar formulario con delete_ids[] via POST
            const f = document.createElement('form'); f.method='POST'; f.style.display='none';
            selected.forEach(id=>{ const inp=document.createElement('input'); inp.type='hidden'; inp.name='delete_ids[]'; inp.value=id; f.appendChild(inp); });
            document.body.appendChild(f); f.submit();
        });
        // AJAX variant for permanent delete
        document.getElementById('btnDeletePermanent').addEventListener('contextmenu', function(e){
            // Ctrl+click (contextmenu) no estándar para demo: enviar via AJAX si el usuario mantiene presionado 'Ctrl' (no en todos los navegadores)
        });

        // Restaurar por action_id (prompt) - usa AJAX
        document.getElementById('btnRestoreByAction').addEventListener('click', function(){
            const aid = prompt('Introduce el action_id para restaurar:');
            if (!aid) return;
            if (!confirm('Restaurar la acción ' + aid + '?')) return;
            const fd = new FormData(); fd.append('ajax','1'); fd.append('restore_by_action_id', aid);
            fetch('inventario_trash.php', { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(resp=>{
                    if (!resp || !resp.ok) { alert('Error: ' + (resp && resp.message?resp.message:'Respuesta inválida')); return; }
                    alert('Restauración por action completada. Restaurados: ' + (resp.restored||0));
                    location.reload();
                }).catch(e=>{ console.error(e); alert('Error al restaurar por action_id'); });
        });
    </script>
</body>
</html>

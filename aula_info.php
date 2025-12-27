<?php
// aula_info.php
// Muestra la lista de bienes de un aula y permite ratificar/actualizar su existencia
require_once __DIR__ . '/vendor/autoload.php';
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);

$aula = isset($_GET['aula']) ? trim($_GET['aula']) : '';
if (!$aula) {
    echo '<div style="color:#b71c1c;font-weight:bold;">Aula no especificada.</div>';
    exit;
}

// Procesar actualización si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar']) && isset($_POST['bien_id'])) {
    foreach ($_POST['bien_id'] as $i => $id) {
        $presente = isset($_POST['presente'][$i]) ? 1 : 0;
        $estado = $conn->real_escape_string($_POST['estado'][$i]);
        $sql = "UPDATE inventario SET presente='$presente', estado='$estado', fecha_verificacion=NOW() WHERE id='$id'";
        $conn->query($sql);
    }
    echo '<div id="msg_exito" style="background:#c8e6c9;color:#256029;padding:10px 16px;border-radius:6px;margin-bottom:14px;">Inventario actualizado correctamente.</div>';
    echo "<script>setTimeout(function(){ var m=document.getElementById('msg_exito'); if(m)m.style.display='none'; }, 2500);</script>";
}

// Obtener bienes del aula

$sql = "SELECT * FROM inventario WHERE aula_funcional = '".$conn->real_escape_string($aula)."' ORDER BY denominacion";
$res = $conn->query($sql);
$datos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Resumen
$total = count($datos);
$presentes = 0;
$ausentes = 0;
foreach($datos as $fila) {
    if (!isset($fila['presente']) || $fila['presente']) $presentes++;
    else $ausentes++;
}

// Estados posibles
$estados = ['Bueno','Regular','Malo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario de <?= htmlspecialchars($aula) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9fb url('fachada_fya44.jpg') no-repeat center center fixed; background-size: cover; margin:0; }
        .contenedor { max-width: 900px; margin: 24px auto; background: rgba(255,255,255,0.98); border-radius: 12px; box-shadow: 0 2px 16px #0002; padding: 24px; }
        h2 { color: #b71c1c; text-align: center; margin-top:0; }
        table { border-collapse: collapse; width: 100%; margin-top: 18px; }
        th, td { border: 1px solid #e57373; padding: 6px 4px; font-size: 0.97em; }
        th { background: #ffcdd2; color: #b71c1c; }
        .btn { background: #b71c1c; color: #fff; font-weight: bold; border: none; border-radius: 4px; padding: 8px 16px; cursor: pointer; margin-top: 12px; }
        .btn:hover { background: #d32f2f; }
        @media (max-width: 700px) {
            .contenedor { padding: 8px; }
            table, th, td { font-size: 0.93em; }
        }
    </style>
</head>
<body>
<div class="contenedor">
    <div style="text-align:center;margin-bottom:10px;">
        <img src="logo_fya44.png" alt="Logo Fe y Alegría 44" style="height:60px;vertical-align:middle;margin-bottom:8px;">
    </div>
    <h2>Inventario del aula: <?= htmlspecialchars($aula) ?></h2>
    <div style="text-align:center;color:#444;margin-bottom:18px;">Lista de bienes y ratificación de existencia</div>
    <div style="background:#f8f8f8;padding:10px 12px;border-radius:8px;max-width:500px;margin:0 auto 16px auto;text-align:center;font-size:1em;">
        <b>Total de bienes:</b> <?= $total ?> &nbsp;|&nbsp;
        <b>Presentes:</b> <span style="color:#388e3c;"><?= $presentes ?></span> &nbsp;|&nbsp;
        <b>Ausentes:</b> <span style="color:#b71c1c;"><?= $ausentes ?></span>
    </div>
    <div style="text-align:right;margin-bottom:8px;">
        <button class="btn" type="button" onclick="window.print()" style="background:#1976d2;">Imprimir inventario del aula</button>
    </div>
    <?php if (count($datos) === 0): ?>
        <div style="color:#b71c1c;font-weight:bold;text-align:center;">No hay bienes registrados para este salón.</div>
    <?php else: ?>
    <form method="post">
    <table>
        <tr>
            <th>Denominación</th>
            <th>Tipo</th>
            <th>Estado</th>
            <th>Presente</th>
            <th>Observaciones</th>
            <th>Últ. verificación</th>
        </tr>
        <?php foreach($datos as $i => $fila): ?>
        <?php
            $ausente = (isset($fila['presente']) && !$fila['presente']);
            $mal_estado = (isset($fila['estado']) && $fila['estado']==='Malo');
        ?>
        <tr style="<?= $ausente ? 'background:#ffebee;' : ($mal_estado ? 'background:#fff3e0;' : '') ?>">
            <td><?= htmlspecialchars($fila['denominacion']) ?></td>
            <td><?= htmlspecialchars($fila['tipo']) ?></td>
            <td>
                <select name="estado[]" style="background:<?= $mal_estado ? '#ffcdd2' : '#fff' ?>;color:#b71c1c;">
                    <?php foreach($estados as $e): ?>
                        <option value="<?= $e ?>"<?= ($fila['estado']==$e?' selected':'') ?>><?= $e ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" name="presente[<?= $i ?>]" value="1"<?= (!isset($fila['presente']) || $fila['presente'] ? ' checked' : '') ?>>
                <input type="hidden" name="bien_id[]" value="<?= $fila['id'] ?>">
            </td>
            <td><?= htmlspecialchars($fila['observaciones']) ?></td>
            <td style="font-size:0.97em;color:#555;">
                <?= isset($fila['fecha_verificacion']) && $fila['fecha_verificacion'] ? date('d/m/Y H:i', strtotime($fila['fecha_verificacion'])) : '-' ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <button type="submit" name="actualizar" class="btn">Ratificar/Actualizar Inventario</button>
    </form>
    <?php endif; ?>
    <div style="margin-top:18px;font-size:0.95em;color:#444;text-align:right;">
        Última actualización: <?= date('d/m/Y H:i') ?>
    </div>
</div>
</body>
</html>

<?php
session_start();
$users_file = __DIR__ . '/admin_users.json';
$users = [];
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true) ?: [];
}
$err = '';
// Soporte para contraseñas en texto (legacy) y para hashes.
// Si se detecta un usuario con clave en texto y se autentica correctamente,
// se actualizará el archivo a usar `pass_hash` (mejora automática).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['user'] ?? '';
    $p = $_POST['pass'] ?? '';
    $matched = false;
    foreach ($users as $idx => $usr) {
        if (!isset($usr['user']) || $usr['user'] !== $u) continue;
        // Si existe pass_hash usar password_verify
        if (!empty($usr['pass_hash'])) {
            if (password_verify($p, $usr['pass_hash'])) {
                $_SESSION['admin'] = $usr['user'];
                $matched = true;
                break;
            }
        } elseif (isset($usr['pass'])) {
            // Legacy: texto plano. Si coincide, autenticamos y actualizamos a hash
            if ($usr['pass'] === $p) {
                // Generar hash y actualizar el archivo (mejora automática)
                $users[$idx]['pass_hash'] = password_hash($p, PASSWORD_DEFAULT);
                unset($users[$idx]['pass']);
                file_put_contents($users_file, json_encode(array_values($users), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $_SESSION['admin'] = $usr['user'];
                $matched = true;
                break;
            }
        }
    }
    if ($matched) {
        header('Location: inventario_central.php');
        exit;
    }
    $err = 'Usuario o clave incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Login - Inventario Central</title>
    <style>body{font-family:Arial;margin:40px;background:#f7f7f7}form{max-width:360px;margin:0 auto;padding:18px;background:#fff;border-radius:8px;box-shadow:0 2px 10px #0002}label{display:block;margin-bottom:8px;color:#b71c1c}input{width:100%;padding:8px;margin-bottom:10px;border:1px solid #ccc;border-radius:4px}button{background:#b71c1c;color:#fff;padding:8px 12px;border:none;border-radius:4px;cursor:pointer}</style>
</head>
<body>
    <form method="post" autocomplete="off">
        <h2 style="text-align:center;color:#b71c1c;margin-top:0">Ingreso Inventario Central</h2>
        <?php if ($err): ?><div style="color:#c62828;margin-bottom:10px"><?php echo htmlspecialchars($err) ?></div><?php endif; ?>
        <label>Usuario<input name="user" required></label>
        <label>Clave<input type="password" name="pass" required></label>
        <div style="text-align:center"><button type="submit">Acceder</button></div>
        <div style="margin-top:10px;color:#666;font-size:0.9em;text-align:center">Nota: en este despliegue simple las credenciales están en <code>admin_users.json</code>. Cámbielas antes de publicar.</div>
    </form>
</body>
</html>

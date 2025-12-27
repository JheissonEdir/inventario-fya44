<?php
// make_admin.php
// Helper para crear/actualizar usuarios admin con password_hash.
// Uso web: abrir en navegador, completar formulario.
// Uso CLI: php make_admin.php usuario contraseña

$users_file = __DIR__ . '/admin_users.json';

function load_users($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function save_users($file, $arr) {
    file_put_contents($file, json_encode(array_values($arr), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

if (PHP_SAPI === 'cli') {
    // CLI mode: php make_admin.php usuario clave
    $argv_user = $argv[1] ?? null;
    $argv_pass = $argv[2] ?? null;
    if (!$argv_user || !$argv_pass) {
        echo "Usage: php make_admin.php usuario contraseña\n";
        exit(1);
    }
    $users = load_users($users_file);
    $found = false;
    foreach ($users as $i => $u) {
        if (isset($u['user']) && $u['user'] === $argv_user) {
            $users[$i]['pass_hash'] = password_hash($argv_pass, PASSWORD_DEFAULT);
            unset($users[$i]['pass']);
            $found = true; break;
        }
    }
    if (!$found) {
        $users[] = ['user'=>$argv_user, 'pass_hash'=>password_hash($argv_pass, PASSWORD_DEFAULT)];
    }
    save_users($users_file, $users);
    echo ($found?"Usuario actualizado":"Usuario creado") . " correctamente.\n";
    exit(0);
}

// Web interface
$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if ($user === '' || $pass === '') {
        $err = 'Usuario y clave requeridos.';
    } else {
        $users = load_users($users_file);
        $found = false;
        foreach ($users as $i => $u) {
            if (isset($u['user']) && $u['user'] === $user) {
                $users[$i]['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                unset($users[$i]['pass']);
                $found = true; break;
            }
        }
        if (!$found) $users[] = ['user'=>$user, 'pass_hash'=>password_hash($pass, PASSWORD_DEFAULT)];
        save_users($users_file, $users);
        $ok = ($found? 'Usuario actualizado correctamente.' : 'Usuario creado correctamente.');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Crear/Actualizar Admin - Inventario Central</title>
<style>body{font-family:Arial;margin:30px;background:#f7f7f7}form{max-width:480px;margin:0 auto;padding:18px;background:#fff;border-radius:8px;box-shadow:0 2px 10px #0002}label{display:block;margin-bottom:8px;color:#b71c1c}input{width:100%;padding:8px;margin-bottom:10px;border:1px solid #ccc;border-radius:4px}button{background:#1976d2;color:#fff;padding:8px 12px;border:none;border-radius:4px;cursor:pointer}.msg{padding:10px;border-radius:6px;margin-bottom:12px}.ok{background:#e8ffe8;border:1px solid #b2ffb2;color:#006400}.err{background:#ffecec;border:1px solid #ffc0c0;color:#c62828}</style>
</head>
<body>
    <form method="post" autocomplete="off">
        <h2 style="text-align:center;color:#b71c1c;margin-top:0">Crear/Actualizar Administrador</h2>
        <?php if ($err): ?><div class="msg err"><?php echo htmlspecialchars($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok) ?></div><?php endif; ?>
        <label>Usuario<input name="user" required></label>
        <label>Clave<input type="password" name="pass" required></label>
        <div style="text-align:center"><button type="submit">Guardar</button></div>
        <div style="margin-top:10px;color:#666;font-size:0.9em;text-align:center">Este formulario genera un hash seguro con <code>password_hash</code> y lo guarda en <code>admin_users.json</code>.</div>
    </form>
</body>
</html>

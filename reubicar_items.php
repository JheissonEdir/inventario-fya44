<?php
// Script para reubicar items a las aulas correctas
session_start();

// Autenticación básica (puedes mejorar esto con tu sistema de login)
if (empty($_SESSION['admin'])) {
    // header('Location: login_central.php');
    // exit;
}

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Error de conexión: ' . $conn->connect_error);
}

// Cargar lista de aulas disponibles desde aulas.json
$aulas_file = __DIR__ . '/aulas.json';
$aulas_disponibles = [];
if (file_exists($aulas_file)) {
    $aulas_json = json_decode(file_get_contents($aulas_file), true);
    if (is_array($aulas_json)) {
        foreach ($aulas_json as $nivel => $lista) {
            $aulas_disponibles[$nivel] = $lista;
        }
    }
}

// Función para obtener aulas de un nivel específico
function obtener_aulas_por_nivel($aulas_disponibles, $nivel) {
    return isset($aulas_disponibles[$nivel]) ? $aulas_disponibles[$nivel] : [];
}

// Procesar reubicación
if (isset($_POST['reubicar']) && isset($_POST['cambios'])) {
    $cambios = json_decode($_POST['cambios'], true);
    $errores = [];
    $exitosos = 0;
    
    if (!is_array($cambios) || empty($cambios)) {
        $mensaje_error = "❌ No se recibieron cambios válidos.";
    } else {
        $conn->begin_transaction();
        
        try {
            foreach ($cambios as $cambio) {
                $id = intval($cambio['id']);
                $nueva_aula = $conn->real_escape_string($cambio['nueva_aula']);
                $nivel = $conn->real_escape_string($cambio['nivel']);
                
                $sql = "UPDATE inventario 
                        SET aula_funcional = ?, 
                            ubicacion = CONCAT('Reubicado a ', ?) 
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $errores[] = "Error preparando consulta para ID $id: " . $conn->error;
                    continue;
                }
                
                $stmt->bind_param('ssi', $nueva_aula, $nueva_aula, $id);
                
                if ($stmt->execute()) {
                    $exitosos++;
                } else {
                    $errores[] = "Error al reubicar item ID $id: " . $stmt->error;
                }
                $stmt->close();
            }
            
            if (empty($errores)) {
                $conn->commit();
                // Redirigir para evitar reenvío del formulario
                $params = [];
                if ($filtro_nivel) $params[] = 'nivel=' . urlencode($filtro_nivel);
                if ($filtro_aula) $params[] = 'aula=' . urlencode($filtro_aula);
                if ($filtro_usuario) $params[] = 'usuario=' . urlencode($filtro_usuario);
                $params[] = 'success=' . urlencode("Se reubicaron exitosamente $exitosos items");
                $redirect = 'reubicar_items.php?' . implode('&', $params);
                header('Location: ' . $redirect);
                exit;
            } else {
                $conn->rollback();
                $mensaje_error = "❌ Hubo errores: " . implode(', ', $errores);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $mensaje_error = "❌ Error en la transacción: " . $e->getMessage();
        }
    }
}

// Mensaje de éxito desde redirección
if (isset($_GET['success'])) {
    $mensaje_exito = $_GET['success'];
}

// Filtros
$filtro_nivel = isset($_GET['nivel']) ? $_GET['nivel'] : '';
$filtro_aula = isset($_GET['aula']) ? $_GET['aula'] : '';
$filtro_usuario = isset($_GET['usuario']) ? $_GET['usuario'] : '';

// Paginación
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50; // Reducido de 1000 a 50 items por página
$offset = ($page - 1) * $per_page;

// Construir consulta para items potencialmente mal ubicados (sin subconsulta para mejor rendimiento)
$sql = "SELECT i.* FROM inventario i WHERE 1=1";

if ($filtro_nivel) {
    $sql .= " AND i.nivel = '" . $conn->real_escape_string($filtro_nivel) . "'";
}
if ($filtro_aula) {
    $sql .= " AND i.aula_funcional = '" . $conn->real_escape_string($filtro_aula) . "'";
}
if ($filtro_usuario) {
    $sql .= " AND i.usuario_responsable LIKE '%" . $conn->real_escape_string($filtro_usuario) . "%'";
}

// Obtener total para paginación
$count_sql = str_replace('SELECT i.*', 'SELECT COUNT(*) as total', $sql);
$count_result = $conn->query($count_sql);
$total_items = 0;
if ($count_result) {
    $count_row = $count_result->fetch_assoc();
    $total_items = $count_row['total'];
}

$sql .= " ORDER BY i.nivel, i.aula_funcional, i.id LIMIT $per_page OFFSET $offset";

$result = $conn->query($sql);
if (!$result) {
    die('Error en la consulta principal: ' . $conn->error);
}

// Obtener lista de usuarios únicos para el filtro
$sql_usuarios = "SELECT DISTINCT usuario_responsable FROM inventario ORDER BY usuario_responsable";
$result_usuarios = $conn->query($sql_usuarios);
$usuarios = [];
if ($result_usuarios) {
    while ($row = $result_usuarios->fetch_assoc()) {
        if (!empty($row['usuario_responsable'])) {
            $usuarios[] = $row['usuario_responsable'];
        }
    }
}

// Obtener lista de aulas únicas
$sql_aulas = "SELECT DISTINCT aula_funcional, nivel FROM inventario ORDER BY nivel, aula_funcional";
$result_aulas = $conn->query($sql_aulas);
$aulas = [];
if ($result_aulas) {
    while ($row = $result_aulas->fetch_assoc()) {
        if (!empty($row['nivel'])) {
            $aulas[$row['nivel']][] = $row['aula_funcional'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reubicar Items de Inventario</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 30px;
        }
        h1 { 
            color: #2d3748; 
            margin-bottom: 10px;
            font-size: 2.2rem;
        }
        .subtitle {
            color: #718096;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success { 
            background: #c6f6d5; 
            color: #22543d; 
            border-left: 4px solid #38a169;
        }
        .alert-error { 
            background: #fed7d7; 
            color: #742a2a; 
            border-left: 4px solid #e53e3e;
        }
        .filters {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 1px solid #e2e8f0;
        }
        .filters h3 {
            margin-bottom: 15px;
            color: #2d3748;
        }
        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        .filter-item label {
            display: block;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }
        .filter-item select, .filter-item input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-success:hover {
            background: #38a169;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.4);
        }
        .btn-danger {
            background: #f56565;
            color: white;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        thead {
            background: #2d3748;
            color: white;
        }
        th {
            padding: 15px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        tbody tr:hover {
            background: #f7fafc;
        }
        .checkbox-cell {
            text-align: center;
            width: 50px;
        }
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .aula-select {
            padding: 8px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            min-width: 150px;
        }
        .nivel-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .nivel-inicial { background: #bee3f8; color: #2c5282; }
        .nivel-primaria { background: #c6f6d5; color: #22543d; }
        .nivel-secundaria { background: #fed7d7; color: #742a2a; }
        .actions-bar {
            background: #edf2f7;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .selected-count {
            font-weight: 600;
            color: #2d3748;
        }
        #cambiosPendientes {
            max-height: 300px;
            overflow-y: auto;
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid #cbd5e0;
        }
        .cambio-item {
            padding: 10px;
            background: white;
            margin-bottom: 10px;
            border-radius: 4px;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Reubicar Items de Inventario</h1>
        <p class="subtitle">Corrige la ubicación de items que fueron registrados en aulas incorrectas</p>
        
        <?php if (isset($mensaje_exito)): ?>
            <div class="alert alert-success"><?php echo $mensaje_exito; ?></div>
        <?php endif; ?>
        
        <?php if (isset($mensaje_error)): ?>
            <div class="alert alert-error"><?php echo $mensaje_error; ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_items; ?></div>
                <div class="stat-label">Total Items</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $result->num_rows; ?></div>
                <div class="stat-label">Items en Esta Página</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="selectedCount">0</div>
                <div class="stat-label">Items Seleccionados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters">
            <h3>🔍 Filtrar Items</h3>
            <form method="GET">
                <div class="filter-group">
                    <div class="filter-item">
                        <label>Nivel:</label>
                        <select name="nivel" onchange="this.form.submit()">
                            <option value="">Todos los niveles</option>
                            <option value="Inicial" <?php echo $filtro_nivel === 'Inicial' ? 'selected' : ''; ?>>Inicial</option>
                            <option value="Primaria" <?php echo $filtro_nivel === 'Primaria' ? 'selected' : ''; ?>>Primaria</option>
                            <option value="Secundaria" <?php echo $filtro_nivel === 'Secundaria' ? 'selected' : ''; ?>>Secundaria</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Aula Actual:</label>
                        <select name="aula" onchange="this.form.submit()">
                            <option value="">Todas las aulas</option>
                            <?php if (isset($aulas[$filtro_nivel]) || empty($filtro_nivel)): ?>
                                <?php 
                                $aulas_mostrar = empty($filtro_nivel) ? $aulas : [$filtro_nivel => $aulas[$filtro_nivel]];
                                foreach ($aulas_mostrar as $nivel => $lista_aulas):
                                    foreach ($lista_aulas as $aula): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($aula); ?>" 
                                            <?php echo $filtro_aula === $aula ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($nivel . ' - ' . $aula); ?>
                                    </option>
                                <?php 
                                    endforeach;
                                endforeach; 
                                ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Usuario Responsable:</label>
                        <select name="usuario" onchange="this.form.submit()">
                            <option value="">Todos los usuarios</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?php echo htmlspecialchars($usuario); ?>" 
                                        <?php echo $filtro_usuario === $usuario ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($usuario); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn btn-primary">Filtrar</button>
                        <?php if ($filtro_nivel || $filtro_aula || $filtro_usuario): ?>
                            <a href="reubicar_items.php" class="btn" style="margin-left: 10px; background: #e2e8f0; color: #2d3748;">Limpiar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Paginación -->
        <?php 
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1): 
        ?>
        <div style="display: flex; justify-content: center; align-items: center; gap: 10px; margin: 20px 0; padding: 15px; background: #f7fafc; border-radius: 8px;">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn" style="background: #667eea; color: white; text-decoration: none;">← Anterior</a>
            <?php endif; ?>
            
            <span style="font-weight: 600; color: #2d3748;">
                Página <?php echo $page; ?> de <?php echo $total_pages; ?>
            </span>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn" style="background: #667eea; color: white; text-decoration: none;">Siguiente →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Barra de acciones -->
        <div class="actions-bar">
            <div>
                <span class="selected-count">Items seleccionados: <span id="selectedCountText">0</span></span>
            </div>
            <div>
                <button class="btn btn-primary" onclick="seleccionarTodos()">Seleccionar Todos</button>
                <button class="btn" style="background: #e2e8f0; color: #2d3748;" onclick="limpiarSeleccion()">Limpiar Selección</button>
                <button class="btn btn-success" onclick="prepararReubicacion()">Vista Previa de Cambios</button>
            </div>
        </div>
        
        <!-- Tabla de items -->
        <div style="overflow-x: auto;">
            <table id="tablaItems">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                        </th>
                        <th>ID</th>
                        <th>Nivel</th>
                        <th>Aula Actual</th>
                        <th>Denominación</th>
                        <th>Marca</th>
                        <th>Usuario Responsable</th>
                        <th>Ubicación</th>
                        <th>Nueva Aula</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $count = 0;
                    while ($row = $result->fetch_assoc()): 
                        $count++;
                        $nivel_class = 'nivel-' . strtolower($row['nivel']);
                    ?>
                        <tr>
                            <td class="checkbox-cell">
                                <input type="checkbox" class="item-checkbox" 
                                       data-id="<?php echo $row['id']; ?>"
                                       data-nivel="<?php echo htmlspecialchars($row['nivel']); ?>"
                                       data-aula-actual="<?php echo htmlspecialchars($row['aula_funcional']); ?>"
                                       data-denominacion="<?php echo htmlspecialchars($row['denominacion']); ?>"
                                       onchange="actualizarContador()">
                            </td>
                            <td><?php echo $row['id']; ?></td>
                            <td>
                                <span class="nivel-badge <?php echo $nivel_class; ?>">
                                    <?php echo htmlspecialchars($row['nivel']); ?>
                                </span>
                            </td>
                            <td><strong><?php echo htmlspecialchars($row['aula_funcional']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['denominacion']); ?></td>
                            <td><?php echo htmlspecialchars($row['marca']); ?></td>
                            <td><?php echo htmlspecialchars($row['usuario_responsable']); ?></td>
                            <td><?php echo htmlspecialchars($row['ubicacion']); ?></td>
                            <td>
                                <select class="aula-select nueva-aula" data-id="<?php echo $row['id']; ?>">
                                    <option value="">-- Seleccionar --</option>
                                    <?php 
                                    $aulas_nivel = obtener_aulas_por_nivel($aulas_disponibles, $row['nivel']);
                                    foreach ($aulas_nivel as $aula): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($aula); ?>">
                                            <?php echo htmlspecialchars($aula); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    
                    <?php if ($count === 0): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #718096;">
                                No se encontraron items con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Vista previa de cambios -->
        <div id="cambiosPendientes" style="display: none;">
            <h3 style="margin-bottom: 15px; color: #2d3748;">📋 Vista Previa de Cambios</h3>
            <div id="listaCambios"></div>
            <form method="POST" id="formReubicacion" style="margin-top: 15px;">
                <input type="hidden" name="cambios" id="cambiosJSON">
                <button type="submit" name="reubicar" class="btn btn-success">
                    ✅ Confirmar y Reubicar Items
                </button>
                <button type="button" class="btn" style="background: #e2e8f0; color: #2d3748;" onclick="cerrarVistPrevia()">
                    Cancelar
                </button>
            </form>
        </div>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="inventario_central.php" class="btn" style="background: #e2e8f0; color: #2d3748;">
                ← Volver al Inventario Central
            </a>
        </div>
    </div>
    
    <script>
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            actualizarContador();
        }
        
        function seleccionarTodos() {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => cb.checked = true);
            document.getElementById('selectAll').checked = true;
            actualizarContador();
        }
        
        function limpiarSeleccion() {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            actualizarContador();
        }
        
        function actualizarContador() {
            const checked = document.querySelectorAll('.item-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = checked;
            document.getElementById('selectedCountText').textContent = checked;
        }
        
        function prepararReubicacion() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Por favor, selecciona al menos un item para reubicar.');
                return;
            }
            
            const cambios = [];
            let errorEncontrado = false;
            
            checkboxes.forEach(cb => {
                const id = cb.dataset.id;
                const nivel = cb.dataset.nivel;
                const aulaActual = cb.dataset.aulaActual;
                const denominacion = cb.dataset.denominacion;
                const selectAula = document.querySelector(`.nueva-aula[data-id="${id}"]`);
                const nuevaAula = selectAula.value;
                
                if (!nuevaAula) {
                    alert(`Por favor, selecciona una nueva aula para el item ID ${id} (${denominacion})`);
                    errorEncontrado = true;
                    return;
                }
                
                cambios.push({
                    id: id,
                    nivel: nivel,
                    aula_actual: aulaActual,
                    nueva_aula: nuevaAula,
                    denominacion: denominacion
                });
            });
            
            if (errorEncontrado) return;
            
            // Mostrar vista previa
            const listaCambios = document.getElementById('listaCambios');
            listaCambios.innerHTML = '';
            
            cambios.forEach(cambio => {
                const div = document.createElement('div');
                div.className = 'cambio-item';
                div.innerHTML = `
                    <strong>ID ${cambio.id}</strong>: ${cambio.denominacion}<br>
                    <span style="color: #e53e3e;">❌ ${cambio.aula_actual}</span> 
                    → 
                    <span style="color: #38a169;">✅ ${cambio.nueva_aula}</span>
                    (${cambio.nivel})
                `;
                listaCambios.appendChild(div);
            });
            
            document.getElementById('cambiosJSON').value = JSON.stringify(cambios);
            document.getElementById('cambiosPendientes').style.display = 'block';
            document.getElementById('cambiosPendientes').scrollIntoView({ behavior: 'smooth' });
        }
        
        function cerrarVistPrevia() {
            document.getElementById('cambiosPendientes').style.display = 'none';
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>

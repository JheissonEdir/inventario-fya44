<?php
// reporte_rapido_aulas.php
// Reporte rápido listo para imprimir agrupado por aulas
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);

// Consulta agrupada por aula


// Obtener todas las aulas y sus niveles para el filtro agrupado
$aulas_por_nivel = [];
$niveles = [];
$res_aulas = $conn->query("SELECT DISTINCT nivel, aula_funcional FROM inventario ORDER BY nivel, aula_funcional");
if ($res_aulas) {
    while ($row = $res_aulas->fetch_assoc()) {
        $nivel = trim($row['nivel']);
        $aula = trim($row['aula_funcional']);
        if ($aula === '') continue;
        if (!isset($aulas_por_nivel[$nivel])) $aulas_por_nivel[$nivel] = [];
        $aulas_por_nivel[$nivel][] = $aula;
        if (!in_array($nivel, $niveles)) $niveles[] = $nivel;
    }
}
$aulas_disp = [];
foreach ($aulas_por_nivel as $nivel => $aulas) {
    foreach ($aulas as $aula) $aulas_disp[] = $aula;
}


// Leer selección del usuario
$aulas_seleccionadas = [];
$mostrar_todo = false;
if (isset($_GET['aulas']) && is_array($_GET['aulas'])) {
    $aulas_seleccionadas = array_filter($_GET['aulas'], fn($a)=>trim($a)!=='');
    if (in_array('__ALL__', $aulas_seleccionadas)) {
        $mostrar_todo = true;
        $aulas_seleccionadas = $aulas_disp; // todas
    }
}

// Si no hay selección, no mostrar nada
if (!empty($aulas_seleccionadas)) {
    $in = implode(',', array_fill(0, count($aulas_seleccionadas), '?'));
    $query = "SELECT nivel, aula_funcional, denominacion, cantidad, estado, procedencia FROM inventario WHERE aula_funcional IN ($in) ORDER BY nivel, aula_funcional, denominacion";
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($aulas_seleccionadas));
    $stmt->bind_param($types, ...$aulas_seleccionadas);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = false;
}

$datos_por_aula = [];
$totales = ['total_items' => 0, 'por_nivel' => [], 'por_estado' => []];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $aula = $row['aula_funcional'];
        if (!isset($datos_por_aula[$aula])) {
            $datos_por_aula[$aula] = [
                'nivel' => $row['nivel'],
                'items' => []
            ];
        }
        $datos_por_aula[$aula]['items'][] = $row;
        $totales['total_items']++;
        $totales['por_nivel'][$row['nivel']] = ($totales['por_nivel'][$row['nivel']] ?? 0) + 1;
        $totales['por_estado'][$row['estado']] = ($totales['por_estado'][$row['estado']] ?? 0) + 1;
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Rápido por Aulas - Inventario Fe y Alegría 44</title>
    <style>
        :root { --fyared: #b71c1c; }
        
        /* Estilos para pantalla */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .btn-imprimir {
            background: #1976d2;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-imprimir:hover {
            background: #1565c0;
            transform: translateY(-2px);
        }
        .btn-volver {
            background: #757575;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
            .aula-header {
                font-size: 22px !important;
                padding: 18px 28px !important;
                page-break-after: avoid;
                font-weight: 900 !important;
                background: #b71c1c !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                box-shadow: 0 6px 20px rgba(139, 0, 0, 0.35) !important;
                border: 2.5px solid #8b0000 !important;
                border-bottom: none !important;
                border-radius: 12px 12px 0 0 !important;
                text-shadow: 2px 2px 8px rgba(0,0,0,0.45) !important;
                margin-bottom: 0 !important;
                background-clip: padding-box !important;
            }
            font-size: 24px;
        }
        
        .header-info {
            text-align: right;
            font-size: 13px;
            color: #666;
        }
        
        /* Portada con título institucional */
        .portada {
            background: linear-gradient(135deg, #b71c1c 0%, #8b0000 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(183, 28, 28, 0.4);
            page-break-after: avoid;
        }
        
        .portada h1 {
            font-size: 32px;
            font-weight: 900;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 3px 3px 8px rgba(0,0,0,0.4);
        }
        
        .portada .subtitulo {
            font-size: 20px;
            font-weight: 700;
            margin: 10px 0;
            text-shadow: 2px 2px 6px rgba(0,0,0,0.3);
        }
        
        .portada .institucion {
            font-size: 15px;
            margin-top: 15px;
            opacity: 0.95;
            font-style: italic;
        }
        
        /* Resumen estadístico mejorado con gráficos */
        .resumen {
            background: white;
            border: 2px solid #e0e0e0;
            padding: 25px 30px;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            page-break-inside: avoid;
        }
        
        .resumen h3 {
            margin: 0 0 20px 0;
            color: var(--fyared);
            font-weight: 900;
            font-size: 22px;
            text-align: center;
            border-bottom: 3px solid var(--fyared);
            padding-bottom: 10px;
        }
        
        .resumen strong {
            font-weight: 800;
            color: #000;
        }
        
        /* Grid de estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f5f5f5 0%, #eeeeee 100%);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #ddd;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        
        .stat-card .numero {
            font-size: 42px;
            font-weight: 900;
            color: var(--fyared);
            line-height: 1;
            margin-bottom: 8px;
        }
        
        .stat-card .label {
            font-size: 14px;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Gráficos de barras horizontales */
        .chart-section {
            margin-bottom: 25px;
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--fyared);
            margin-bottom: 12px;
            text-transform: uppercase;
        }
        
        .bar-chart {
            margin-bottom: 20px;
        }
        
        .bar-item {
            margin-bottom: 12px;
        }
        
        .bar-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #333;
        }
        
        .bar-container {
            background: #e0e0e0;
            height: 28px;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #b71c1c 0%, #d32f2f 100%);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 12px;
            color: white;
            font-weight: 700;
            font-size: 13px;
            transition: width 1s ease-out;
            box-shadow: 0 2px 6px rgba(183, 28, 28, 0.4);
        }
        
        .bar-fill.estado-bueno {
            background: linear-gradient(90deg, #2e7d32 0%, #43a047 100%);
            box-shadow: 0 2px 6px rgba(46, 125, 50, 0.4);
        }
        
        .bar-fill.estado-regular {
            background: linear-gradient(90deg, #f57c00 0%, #fb8c00 100%);
            box-shadow: 0 2px 6px rgba(245, 124, 0, 0.4);
        }
        
        .bar-fill.estado-malo {
            background: linear-gradient(90deg, #c62828 0%, #e53935 100%);
            box-shadow: 0 2px 6px rgba(198, 40, 40, 0.4);
        }
        
        .resumen-page {
            page-break-after: always;
        }
        
        .aula-section {
            margin-bottom: 30px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        @media print {
            .aula-section {
                page-break-before: always !important;
            }
            .aula-section:first-of-type {
                page-break-before: auto !important;
            }
        }
        
        .aula-header {
            background: linear-gradient(135deg, #b71c1c 0%, #8b0000 100%) !important;
            color: #fff !important;
            padding: 18px 28px !important;
            font-size: 22px !important;
            font-weight: 900 !important;
            border-radius: 12px 12px 0 0 !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 20px rgba(139, 0, 0, 0.35) !important;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.45) !important;
            border: 2.5px solid #8b0000 !important;
            border-bottom: none !important;
            margin-bottom: 0 !important;
        }
        
        .aula-nombre {
            font-size: 24px;
            letter-spacing: 1px;
            font-weight: 900;
            text-transform: uppercase;
        }
        
        .aula-nivel {
            font-size: 16px;
            background: rgba(255,255,255,0.18);
            padding: 7px 18px;
            border-radius: 8px;
            font-weight: 700;
            border: 1.5px solid rgba(255,255,255,0.35);
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.10);
            letter-spacing: 0.5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border: 2px solid #bdbdbd;
        }
        
        th {
            background: #b71c1c !important;
            color: #fff !important;
            padding: 12px 10px;
            text-align: left;
            font-weight: 900 !important;
            border: 1.5px solid #e64949ff;
            font-size: 16px;
            letter-spacing: 0.5px;
            text-shadow: 1px 1px 4px #3338;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        td {
            padding: 10px 10px;
            border: 1px solid #d0d0d0;
            background: white;
            font-weight: normal;
            color: #333;
        }
        
        /* Estilo zebra mejorado con más contraste */
        tr:nth-child(odd) td {
            background: #ffffff;
        }
        
        tr:nth-child(even) td {
            background: #f5f5f5;
        }
        
        tr:hover td {
            background: #e3f2fd !important;
        }
        
        .cantidad-col {
            text-align: center;
            font-weight: 700;
            color: var(--fyared);
        }
        
        .estado-bueno { color: #2e7d32; font-weight: 600; }
        .estado-regular { color: #f57c00; font-weight: 600; }
        .estado-malo { color: #c62828; font-weight: 600; }
        
        /* ===== ESTILOS DE IMPRESIÓN ===== */
        @media print {
            @page {
                size: A4 portrait;
                margin: 6mm 6mm 8mm 6mm;
            }
            body {
                background: white;
                padding: 0;
                font-size: 12px !important;
            }
            .no-print {
                display: none !important;
            }
            .container {
                max-width: 100%;
                padding: 0;
                box-shadow: none;
            }
            .aula-header {
                font-size: 15px !important;
                padding: 8px 10px !important;
            }
            .aula-nombre {
                font-size: 13px !important;
            }
            .aula-nivel {
                font-size: 11px !important;
                padding: 3px 8px !important;
            }
            table {
                font-size: 11px !important;
                margin-bottom: 4px !important;
            }
            th, td {
                padding: 4px 5px !important;
            }
            th {
                background: #b71c1c !important;
                color: #fff !important;
                font-size: 13px !important;
                font-weight: 900 !important;
                border: 1.5px solid #8b0000 !important;
                letter-spacing: 0.5px !important;
                text-shadow: 1px 1px 4px #3338 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .portada, .resumen, .resumen-page {
                page-break-after: avoid;
                margin-bottom: 8px !important;
                padding: 8px 8px !important;
            }
            .stat-card .numero {
                font-size: 18px !important;
            }
            .stat-card .label {
                font-size: 9px !important;
            }
        }
            
            .container {
                max-width: 100%;
                padding: 0;
                box-shadow: none;
            }
            
            .resumen-page {
                page-break-after: always;
            }
            
            .portada {
                background: #b71c1c !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                padding: 30px 20px;
                margin-bottom: 20px;
            }
            
            .portada h1 {
                font-size: 26px;
            }
            
            .portada .subtitulo {
                font-size: 16px;
            }
            
            .portada .institucion {
                font-size: 12px;
            }
            
            .resumen {
                page-break-inside: avoid;
                background: white !important;
                border: 2px solid #ccc !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .resumen h3 {
                font-size: 16px;
                border-bottom: 2px solid #b71c1c !important;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                background: #f5f5f5 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                padding: 12px;
            }
            
            .stat-card .numero {
                font-size: 32px;
            }
            
            .stat-card .label {
                font-size: 11px;
            }
            
            .chart-section {
                margin-bottom: 15px;
            }
            
            .chart-title {
                font-size: 13px;
            }
            
            .bar-label {
                font-size: 10px;
            }
            
            .bar-container {
                height: 22px;
            }
            
            .bar-fill {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-size: 10px;
                padding-right: 8px;
            }
            
            .bar-fill.estado-bueno {
                background: #2e7d32 !important;
            }
            
            .bar-fill.estado-regular {
                background: #f57c00 !important;
            }
            
            .bar-fill.estado-malo {
                background: #c62828 !important;
            }
        </style>
            <div class="portada">
                <h1>INVENTARIO GENERAL 2025</h1>
                <div class="subtitulo">SAN IGNACIO DE LOYOLA</div>
                <div class="subtitulo">FE Y ALEGRÍA 44 - ANDAHUAYLILLAS</div>
                <div class="institucion">Reporte de Bienes, Fungibles y Donaciones</div>
                <div class="resumen">
                    <h3>📊 Resumen Estadístico General</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="numero"><?= $totales['total_items'] ?></div>
                            <div class="label">Total de Bienes</div>
                        </div>
                        <div class="stat-card">
                            <div class="numero"><?= count($datos_por_aula) ?></div>
                            <div class="label">Aulas con Inventario</div>
                        </div>
                        <div class="stat-card">
                            <div class="numero"><?= count($totales['por_nivel']) ?></div>
                            <div class="label">Niveles Educativos</div>
                        </div>
                    </div>
                    <div class="chart-section">
                        <div class="chart-title">📚 Distribución por Nivel Educativo</div>
                        <div class="bar-chart">
                            <?php 
                            $max_nivel = !empty($totales['por_nivel']) ? max($totales['por_nivel']) : 0;
                            foreach($totales['por_nivel'] as $nivel => $cant): 
                                $porcentaje = ($max_nivel > 0) ? ($cant / $max_nivel) * 100 : 0;
                            ?>
                                <div class="bar-item">
                                    <div class="bar-label">
                                        <span><?= htmlspecialchars($nivel) ?></span>
                                        <span><?= $cant ?> items (<?= round(($cant / $totales['total_items']) * 100, 1) ?>%)</span>
                                    </div>
                                    <div class="bar-container">
                                        <div class="bar-fill" style="width: <?= $porcentaje ?>%">
                                            <?= $cant ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="chart-section">
                        <div class="chart-title">🔧 Distribución por Estado de Conservación</div>
                        <div class="bar-chart">
                            <?php 
                            $max_estado = !empty($totales['por_estado']) ? max($totales['por_estado']) : 0;
                            foreach($totales['por_estado'] as $estado => $cant): 
                                $porcentaje_estado = ($max_estado > 0) ? ($cant / $max_estado) * 100 : 0;
                                $clase_estado = 'estado-' . strtolower($estado);
                            ?>
                                <div class="bar-item">
                                    <div class="bar-label">
                                        <span><?= htmlspecialchars($estado) ?></span>
                                        <span><?= $cant ?> items (<?= round(($cant / $totales['total_items']) * 100, 1) ?>%)</span>
                                    </div>
                                    <div class="bar-container">
                                        <div class="bar-fill <?= $clase_estado ?>" style="width: <?= $porcentaje_estado ?>%">
                                            <?= $cant ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="text-align:center;margin-top:25px;padding-top:20px;border-top:2px solid #e0e0e0;color:#666;font-size:13px;">
                        <strong>Fecha de generación:</strong> <?= date('d/m/Y H:i') ?> | <strong>Generado por:</strong> Sistema de Inventario Fe y Alegría 44
                    </div>
                </div>
            </div>
    </style>
</head>
<body>
    <div class="no-print">
        <form method="get" style="margin-bottom:22px;display:flex;align-items:flex-start;gap:22px;justify-content:center;flex-wrap:wrap;">
            <div style="display:flex;flex-direction:column;align-items:flex-start;max-height:320px;overflow-y:auto;min-width:320px;background:#fff3f3;border:2px solid #b71c1c;border-radius:10px;padding:18px 18px 10px 18px;box-shadow:0 2px 8px #b71c1c22;">
                <b style="margin-bottom:10px;font-size:18px;color:#b71c1c;">Filtrar por aula(s):</b>
                <label style="font-weight:bold;margin-bottom:8px;font-size:16px;">
                    <?php
                    // Determinar si todas las aulas están seleccionadas
                    $todas_seleccionadas = !empty($aulas_disp) && !array_diff($aulas_disp, $aulas_seleccionadas);
                    ?>
                    <input type="checkbox" name="aulas[]" value="__ALL__" id="check_all_aulas"
                        <?= $todas_seleccionadas ? 'checked' : '' ?>>
                    <span style="color:#b71c1c;">Todo</span>
                </label>
                <?php foreach($niveles as $nivel): ?>
                    <?php
                    $aulas_nivel = $aulas_por_nivel[$nivel];
                    $todas_nivel = !empty($aulas_nivel) && !array_diff($aulas_nivel, $aulas_seleccionadas);
                    ?>
                    <div style="margin-bottom:7px;margin-top:7px;padding:6px 0 2px 0;border-bottom:1px solid #e0e0e0;width:100%;">
                        <label style="font-weight:bold;font-size:15px;">
                            <input type="checkbox" class="check_nivel" data-nivel="<?= htmlspecialchars($nivel) ?>" <?= $todas_nivel ? 'checked' : '' ?>>
                            <span style="color:#1976d2;">Nivel: <?= htmlspecialchars($nivel) ?></span>
                        </label>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px 18px;margin-bottom:2px;">
                        <?php foreach($aulas_nivel as $aula): ?>
                            <label style="font-weight:normal;margin-bottom:2px;min-width:120px;">
                                <input type="checkbox" name="aulas[]" value="<?= htmlspecialchars($aula) ?>" class="check_aula" data-nivel="<?= htmlspecialchars($nivel) ?>"
                                    <?= in_array($aula, $aulas_seleccionadas) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($aula) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-imprimir" style="background:#b71c1c;font-size:18px;padding:14px 32px;">Filtrar</button>
            <a href="reporte_rapido_aulas.php" class="btn-volver" style="font-size:18px;padding:14px 32px;">Quitar Filtro</a>
        </form>
        <script>
        // Manejo de la casilla "Todo" y por nivel
        document.addEventListener('DOMContentLoaded', function() {
            var checkAll = document.getElementById('check_all_aulas');
            var checks = document.querySelectorAll('.check_aula');
            var checksNivel = document.querySelectorAll('.check_nivel');
            // Todo
            if (checkAll) {
                checkAll.addEventListener('change', function() {
                    for (var i = 0; i < checks.length; i++) {
                        checks[i].checked = this.checked;
                    }
                    for (var i = 0; i < checksNivel.length; i++) {
                        checksNivel[i].checked = this.checked;
                    }
                });
            }
            // Por nivel
            checksNivel.forEach(function(nivelCheck) {
                nivelCheck.addEventListener('change', function() {
                    var nivel = this.getAttribute('data-nivel');
                    var checksAula = document.querySelectorAll('.check_aula[data-nivel="' + nivel + '"]');
                    for (var i = 0; i < checksAula.length; i++) {
                        checksAula[i].checked = this.checked;
                    }
                    // Si todos los niveles están marcados, marcar "Todo"
                    var allNivelChecked = Array.from(checksNivel).every(c => c.checked);
                    if (checkAll) checkAll.checked = allNivelChecked;
                });
            });
            // Si todas las aulas están marcadas, marcar "Todo" y niveles
            checks.forEach(function(aulaCheck) {
                aulaCheck.addEventListener('change', function() {
                    var nivel = this.getAttribute('data-nivel');
                    if (nivel) {
                        var checksAula = document.querySelectorAll('.check_aula[data-nivel="' + nivel + '"]');
                        var nivelCheck = document.querySelector('.check_nivel[data-nivel="' + nivel + '"]');
                        if (nivelCheck) {
                            var allAulaNivelChecked = Array.from(checksAula).every(c => c.checked);
                            nivelCheck.checked = allAulaNivelChecked;
                        }
                    }
                    var allChecked = Array.from(checks).every(c => c.checked);
                    if (checkAll) checkAll.checked = allChecked;
                });
            });
        });
        </script>
        <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir Reporte</button>
        <a href="inventario_central.php" class="btn-volver">← Volver al Inventario</a>
    </div>
    
    <div class="container">
        <!-- Header con logo (visible en pantalla, oculto en impresión) -->
        <div class="header no-print">
            <img src="logo_fya44.png" alt="Logo Fe y Alegría 44">
            <div>
                <h1>Reporte Rápido por Aulas</h1>
                <div class="header-info">
                    Colegio Fe y Alegría 44<br>
                    Fecha: <?= date('d/m/Y H:i') ?>
                </div>
            </div>
        </div>
        
        <?php if (empty($datos_por_aula)): ?>
            <div style="text-align:center;padding:40px;color:#999;">
                <h3>No hay datos para mostrar</h3>
                <p>El inventario está vacío o no se pudo conectar a la base de datos.</p>
            </div>
        <?php else: ?>
            <?php 
            $nivel_anterior = null;
            foreach($datos_por_aula as $aula => $data): 
                $es_nuevo_nivel = ($nivel_anterior !== null && $nivel_anterior !== $data['nivel']);
                $nivel_anterior = $data['nivel'];
            ?>
                <div class="aula-section" <?= $es_nuevo_nivel ? 'data-new-nivel="true"' : '' ?> style="margin-bottom:40px;">
                    <div class="aula-header">
                        <span class="aula-nombre">📍 <?= htmlspecialchars($aula) ?></span>
                        <span class="aula-nivel" style="background:rgba(255,255,255,0.18);padding:7px 18px;border-radius:8px;font-weight:700;border:1.5px solid rgba(255,255,255,0.35);color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.10);font-size:16px;letter-spacing:0.5px;">
                            <?= htmlspecialchars($data['nivel']) ?> • <?= count($data['items']) ?> items
                        </span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:45%">Denominación</th>
                                <th style="width:10%">Cantidad</th>
                                <th style="width:20%">Estado</th>
                                <th style="width:25%">Procedencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data['items'] as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['denominacion']) ?></td>
                                    <td class="cantidad-col"><?= htmlspecialchars($item['cantidad'] ?? '1') ?></td>
                                    <td class="estado-<?= strtolower($item['estado']) ?>">
                                        <?= htmlspecialchars($item['estado']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['procedencia']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div style="text-align:center;margin-top:30px;padding-top:15px;border-top:2px solid #ddd;color:#666;font-size:12px;">
            Documento generado automáticamente - Uso interno - Colegio Fe y Alegría 44
        </div>
    </div>
</body>
</html>

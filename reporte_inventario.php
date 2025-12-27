<?php
// reporte_inventario.php
// Formulario de filtros y exportación a Excel/PDF
require_once __DIR__ . '/vendor/autoload.php'; // Para PHPSpreadsheet y Dompdf
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'inventario_escolar';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);



// Filtros personalizados
$niveles = ['Inicial', 'Primaria', 'Secundaria'];
$tipos = ['Mobiliario', 'Equipo', 'Material', 'Otro'];
$procedencias = ['UGEL', 'Fe y Alegría', 'Jesuitas', 'Otra'];
$estados = ['Bueno', 'Regular', 'Malo'];


// Validar rutas de archivos y usar consultas preparadas
$aulas_json_path = __DIR__ . '/aulas.json';
if (!file_exists($aulas_json_path)) {
    die('Error: El archivo aulas.json no existe.');
}
$aulas_json = json_decode(file_get_contents($aulas_json_path), true);
$aulas = [];
if (!empty($_GET['nivel']) && isset($aulas_json[$_GET['nivel']])) {
    $aulas = $aulas_json[$_GET['nivel']];
} else {
    // Si no hay nivel seleccionado, juntar todas sin duplicados
    $aulas = array_values(array_unique(array_merge(...array_values($aulas_json))));
}

// Vista rápida: estado por aula (si alguien registró inventario en esa aula)
if (isset($_GET['aulas_status'])) {
    header('Content-Type: text/html; charset=utf-8');
    // Obtener resumen por aula desde la BD
    // Detectar si existe la columna 'cantidad' para sumar su total por aula
    $has_cantidad = false;
    $colChk = $conn->query("SHOW COLUMNS FROM inventario LIKE 'cantidad'");
    if ($colChk && $colChk->num_rows > 0) {
        $has_cantidad = true;
    }

    if ($has_cantidad) {
        $sql = "SELECT nivel, aula_funcional, COUNT(*) AS total_items, SUM(COALESCE(cantidad,0)) AS total_cantidad, MAX(fecha_registro) AS last_ts, GROUP_CONCAT(DISTINCT COALESCE(usuario_responsable, '') SEPARATOR '; ') AS usuarios FROM inventario GROUP BY nivel, aula_funcional";
    } else {
        $sql = "SELECT nivel, aula_funcional, COUNT(*) AS total_items, MAX(fecha_registro) AS last_ts, GROUP_CONCAT(DISTINCT COALESCE(usuario_responsable, '') SEPARATOR '; ') AS usuarios FROM inventario GROUP BY nivel, aula_funcional";
    }
    $res = $conn->query($sql);
    $map = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $lvl = $r['nivel'] ?? '';
            $aula = $r['aula_funcional'] ?? '';
            if (!isset($map[$lvl])) $map[$lvl] = [];
            $map[$lvl][$aula] = $r;
        }
    }

    // Preparar líneas legibles para posible exportación PDF
    $lines = [];
    $selected_nivel = isset($_GET['nivel']) ? $_GET['nivel'] : '';
    // Contar aulas que se mostrarán según el filtro de nivel
    $displayed_aulas = 0;
    foreach (array_keys($aulas_json) as $nkey_count) {
        if ($selected_nivel !== '' && $nkey_count !== $selected_nivel) continue;
        $lista_tmp = $aulas_json[$nkey_count] ?? [];
        if (is_array($lista_tmp)) $displayed_aulas += count($lista_tmp);
    }
    foreach (array_keys($aulas_json) as $nivelKey) {
        if ($selected_nivel !== '' && $nivelKey !== $selected_nivel) continue;
        $lista = $aulas_json[$nivelKey] ?? [];
        if (!is_array($lista) || count($lista) === 0) continue;
        foreach ($lista as $aulaName) {
            $row = $map[$nivelKey][$aulaName] ?? null;
            if ($row && trim($row['usuarios']) !== '') {
                $usuariosArr = array_filter(array_map('trim', explode(';', $row['usuarios'])));
                $primero = $usuariosArr[0] ?? '';
                $lines[] = sprintf("Nivel %s — Aula %s: %s registró su inventario (%d ítems)", $nivelKey, $aulaName, $primero, intval($row['total_items']));
            } else {
                $lines[] = sprintf("Nivel %s — Aula %s: Nadie registró el inventario aún", $nivelKey, $aulaName);
            }
        }
    }

    // Si el usuario pidió exportar en PDF desde la misma vista, generar PDF simple (portrait)
    if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
        // Encabezado con logo (inline base64 si existe)
        $logoPath = __DIR__ . '/logo_fya44.png';
        $logoData = '';
        if (file_exists($logoPath)) {
            $img = file_get_contents($logoPath);
            $b64 = base64_encode($img);
            $logoData = 'data:image/png;base64,' . $b64;
        }

        // Construir PDF con la misma tabla resumida (cabecera, zebra, bordes)
        $htmlPdf = '<!doctype html><html><head><meta charset="utf-8"><style>';
        $htmlPdf .= 'body{font-family:DejaVu Sans, Arial, sans-serif;color:#222;font-size:11px;margin:24mm;}';
        $htmlPdf .= '.header{display:flex;align-items:center;gap:12px;margin-bottom:8px}';
        $htmlPdf .= '.title{font-size:16px;color:#b71c1c;font-weight:800} .sub{font-size:11px;color:#444}';
        $htmlPdf .= '.summary-table{width:100%;border-collapse:collapse;border:1px solid #e6e6e6;font-size:11px;margin-top:8px}';
        $htmlPdf .= '.summary-table th{background:#b71c1c;color:#fff;padding:8px;border:1px solid #e6e6e6;text-align:left}';
        $htmlPdf .= '.summary-table td{padding:6px;border:1px solid #e6e6e6}';
        $htmlPdf .= '.summary-table tr:nth-child(even){background:#fbfbfb}';
        $htmlPdf .= '.summary-table tr:hover{background:#fff3f3}';
        $htmlPdf .= '@page { margin: 24mm 18mm 24mm 18mm; }';
        $htmlPdf .= '</style></head><body>';
        $htmlPdf .= '<div class="header">';
        if ($logoData) $htmlPdf .= '<div style="flex:0 0 72px"><img src="' . $logoData . '" style="height:64px;width:auto;border-radius:6px;"></div>';
        $htmlPdf .= '<div style="flex:1">';
        $htmlPdf .= '<div class="title">Inventario - Colegio Fe y Alegría 44</div>';
        $htmlPdf .= '<div class="sub">Resumen por aula</div>';
        $htmlPdf .= '</div>';
        $htmlPdf .= '<div style="flex:0 0 120px;text-align:right;font-size:11px;color:#555">Generado: ' . date('Y-m-d H:i') . '</div>';
        $htmlPdf .= '</div>';

        // Tabla
        $htmlPdf .= '<table class="summary-table"><thead><tr><th>Nivel</th><th>Aula</th><th>Tiene inventario</th><th>Total ítems</th>';
        if ($has_cantidad) $htmlPdf .= '<th>Cantidad total</th>';
        $htmlPdf .= '<th>Usuarios</th><th>Último registro</th></tr></thead><tbody>';

        foreach (array_keys($aulas_json) as $nivelKey) {
            if ($selected_nivel !== '' && $nivelKey !== $selected_nivel) continue;
            $lista = $aulas_json[$nivelKey] ?? [];
            if (!is_array($lista) || count($lista) === 0) continue;
            foreach ($lista as $aulaName) {
                $row = $map[$nivelKey][$aulaName] ?? null;
                $has = $row ? 'Sí' : 'No';
                $total_items = $row ? intval($row['total_items']) : 0;
                $total_cantidad = $has_cantidad && $row ? intval($row['total_cantidad'] ?? 0) : null;
                $usuarios = $row && trim($row['usuarios']) !== '' ? htmlspecialchars($row['usuarios']) : 'Nadie';
                $last = $row && $row['last_ts'] ? htmlspecialchars($row['last_ts']) : '-';
                $htmlPdf .= '<tr>';
                $htmlPdf .= '<td>' . htmlspecialchars($nivelKey) . '</td>';
                $htmlPdf .= '<td>' . htmlspecialchars($aulaName) . '</td>';
                $htmlPdf .= '<td>' . $has . '</td>';
                $htmlPdf .= '<td>' . $total_items . '</td>';
                if ($has_cantidad) $htmlPdf .= '<td>' . $total_cantidad . '</td>';
                $htmlPdf .= '<td>' . $usuarios . '</td>';
                $htmlPdf .= '<td>' . $last . '</td>';
                $htmlPdf .= '</tr>';
            }
        }

        $htmlPdf .= '</tbody></table>';
        $htmlPdf .= '</body></html>';

        // Preparar opciones y objeto Dompdf
        @ini_set('memory_limit', '512M');
        $optionsPdf = new Dompdf\Options();
        $optionsPdf->set('isRemoteEnabled', true);
        $optionsPdf->set('isHtml5ParserEnabled', true);
        $optionsPdf->set('enable_font_subsetting', true);
        $optionsPdf->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf\Dompdf($optionsPdf);

        $dompdf->loadHtml($htmlPdf);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'resumen_aulas_' . date('Ymd_His') . '.pdf';
        $dompdf->stream($filename, ['Attachment' => 1]);
        $conn->close();
        exit;
    }

    // Si no se pidió PDF, renderizar la página HTML con la lista legible (solo)
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Estado por Aula - Inventario</title>
        <style>
            body{font-family:Arial;margin:18px;background:#fbfbfc}
            .panel{max-width:1100px;margin:12px auto;background:#fff;border-radius:8px;padding:12px;box-shadow:0 4px 12px rgba(0,0,0,0.04)}
            h2{color:#b71c1c;text-align:center}
            /* Ocultar la lista <ul> y mostrar sólo la tabla resumida */
            .panel ul{display:none}
            .muted{color:#666;font-size:0.95em;margin-top:8px;text-align:center}
            /* Tabla resumida: cabecera con color, líneas y zebra */
            .summary-table{width:100%;border-collapse:collapse;border:1px solid #e6e6e6;font-size:13px}
            .summary-table th{background:#b71c1c;color:#fff;padding:10px;border:1px solid #e6e6e6;text-align:left}
            .summary-table td{padding:8px;border:1px solid #e6e6e6}
            .summary-table tr:nth-child(even){background:#fbfbfb}
            .summary-table tr:hover{background:#fff3f3}
        </style>
    </head>
    <body>
        <h2>Resumen legible por aula</h2>
        <div class="panel">
            <p style="max-width:1000px;margin:6px auto;color:#444">Lista legible indicando si el aula tiene inventario y quién lo registró.</p>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <div>
                    <button class="btn" type="button" onclick="window.location.href='inventario_central.php'" style="background:#777;margin-right:8px;padding:8px 12px;border-radius:8px;color:#fff;">Volver</button>
                    <a href="inventario_central.php" class="btn ghost" style="background:transparent;color:#b71c1c;padding:8px 12px;border-radius:8px;text-decoration:none;border:1px solid rgba(183,28,28,0.12);">Ir a Inventario</a>
                </div>
                <div style="text-align:right;"><a href="?aulas_status=1&exportar=pdf<?= $selected_nivel? '&nivel=' . urlencode($selected_nivel) : '' ?>" class="btn" style="background:#6a1b9a;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;">Exportar PDF</a></div>
            </div>
            <form method="get" id="form_nivel" style="margin-bottom:8px;display:flex;align-items:center;gap:12px;">
                <input type="hidden" name="aulas_status" value="1">
                <label style="font-weight:700;margin-right:8px;">Nivel:
                    <select name="nivel" id="select_nivel" onchange="document.getElementById('form_nivel').submit()" style="padding:6px;border-radius:6px;border:1px solid #ccc;">
                        <option value="">Todos</option>
                        <?php foreach (array_keys($aulas_json) as $nkey) echo '<option value="'.htmlspecialchars($nkey).'"'.(($selected_nivel===$nkey)?' selected':'').'>'.htmlspecialchars($nkey).'</option>'; ?>
                    </select>
                </label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="btn secondary" style="padding:6px 10px;border-radius:6px;">Aplicar</button>
                    <button type="button" onclick="document.getElementById('select_nivel').value='';document.getElementById('form_nivel').submit();" class="btn ghost" style="padding:6px 10px;border-radius:6px;">Borrar</button>
                </div>
            </form>

            <div style="margin-bottom:10px;color:#444;font-weight:600;">Mostrando <span style="color:#b71c1c;"><?= $displayed_aulas ?></span> aulas<?= $selected_nivel?(' del nivel "'.htmlspecialchars($selected_nivel).'"') : ' (todos los niveles)' ?>.</div>

            <ul>
            <?php
            // Mostrar frases legibles simples (sin duplicados)
            foreach (array_keys($aulas_json) as $nivelKey) {
                if ($selected_nivel !== '' && $nivelKey !== $selected_nivel) continue;
                $lista = $aulas_json[$nivelKey] ?? [];
                if (!is_array($lista) || count($lista) === 0) continue;
                foreach ($lista as $aulaName) {
                    $row = $map[$nivelKey][$aulaName] ?? null;
                    if ($row && trim($row['usuarios']) !== '') {
                        $usuariosArr = array_filter(array_map('trim', explode(';', $row['usuarios'])));
                        $primero = $usuariosArr[0] ?? '';
                        $txt = sprintf("Nivel %s — Aula %s: %s registró su inventario (%d ítems)", htmlspecialchars($nivelKey), htmlspecialchars($aulaName), htmlspecialchars($primero), intval($row['total_items']));
                    } else {
                        $txt = sprintf("Nivel %s — Aula %s: Nadie registró el inventario aún", htmlspecialchars($nivelKey), htmlspecialchars($aulaName));
                    }
                    echo '<li style="margin-bottom:6px">' . $txt . '</li>';
                }
            }
            ?>
            </ul>
            <div style="margin-top:14px">
                <h4 style="margin:8px 0;color:#b71c1c">Tabla resumida por aula</h4>
                <div style="overflow-x:auto;">
                <table class="summary-table">
                    <thead>
                        <tr><th>Nivel</th><th>Aula</th><th>Tiene inventario</th><th>Total ítems</th><?php if($has_cantidad) echo '<th>Cantidad total</th>';?> <th>Usuarios</th><th>Último registro</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach (array_keys($aulas_json) as $nivelKey) {
                        if ($selected_nivel !== '' && $nivelKey !== $selected_nivel) continue;
                        $lista = $aulas_json[$nivelKey] ?? [];
                        if (!is_array($lista) || count($lista) === 0) continue;
                        foreach ($lista as $aulaName) {
                            $row = $map[$nivelKey][$aulaName] ?? null;
                            $has = $row ? 'Sí' : 'No';
                            $total_items = $row ? intval($row['total_items']) : 0;
                            $total_cantidad = $has_cantidad && $row ? intval($row['total_cantidad'] ?? 0) : null;
                            $usuarios = $row && trim($row['usuarios']) !== '' ? htmlspecialchars($row['usuarios']) : 'Nadie';
                            $last = $row && $row['last_ts'] ? htmlspecialchars($row['last_ts']) : '-';
                            echo '<tr>';
                            echo '<td>'.htmlspecialchars($nivelKey).'</td>';
                            echo '<td>'.htmlspecialchars($aulaName).'</td>';
                            echo '<td>'.$has.'</td>';
                            echo '<td>'.$total_items.'</td>';
                            if($has_cantidad) echo '<td>'.$total_cantidad.'</td>';
                            echo '<td>'.$usuarios.'</td>';
                            echo '<td>'.$last.'</td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="muted">Total aulas listadas: <?= count(array_reduce($aulas_json, function($c,$arr){ return array_merge($c,$arr); }, [])) ?></div>
        </div>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

$where = [];
if (!empty($_GET['nivel'])) {
    $nivel = $conn->real_escape_string($_GET['nivel']);
    $where[] = "nivel = ?";
}
if (!empty($_GET['tipo'])) {
    $tipo = $conn->real_escape_string($_GET['tipo']);
    $where[] = "tipo = ?";
}
if (!empty($_GET['procedencia'])) {
    $procedencia = $conn->real_escape_string($_GET['procedencia']);
    $where[] = "procedencia = ?";
}
if (!empty($_GET['estado'])) {
    $estado = $conn->real_escape_string($_GET['estado']);
    $where[] = "estado = ?";
}
if (!empty($_GET['aula_funcional'])) {
    $aula_funcional = $conn->real_escape_string($_GET['aula_funcional']);
    $where[] = "aula_funcional = ?";
}
if (!empty($_GET['denominacion'])) {
    $denominacion = $conn->real_escape_string($_GET['denominacion']);
    $where[] = "denominacion LIKE ?";
}
if (!empty($_GET['usuario_responsable'])) {
    $usuario_responsable = $conn->real_escape_string($_GET['usuario_responsable']);
    $where[] = "usuario_responsable LIKE ?";
}
if (!empty($_GET['fecha_inicio'])) {
    $fecha_inicio = $conn->real_escape_string($_GET['fecha_inicio']);
    $where[] = "fecha_registro >= ?";
}
if (!empty($_GET['fecha_fin'])) {
    $fecha_fin = $conn->real_escape_string($_GET['fecha_fin']);
    $where[] = "fecha_registro <= ?";
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Consulta
$all_cols = [
    'nivel'=>'Nivel',
    'aula_funcional'=>'Aula',
    'denominacion'=>'Denominación',
    'cantidad'=>'Cantidad',
    'marca'=>'Marca',
    'modelo'=>'Modelo',
    'tipo'=>'Tipo',
    'color'=>'Color',
    'serie'=>'Serie',
    'documento_alta'=>'Doc. Alta',
    'fecha_compra'=>'Fecha Compra',
    'numero_documento'=>'N° Doc',
    'estado'=>'Estado',
    'procedencia'=>'Procedencia',
    'observaciones'=>'Obs.',
    'usuario_responsable'=>'Usuario',
    'ubicacion'=>'Ubicación',
    'fecha_registro'=>'Fecha Registro'
];
$cols = isset($_GET['columnas']) && is_array($_GET['columnas']) ? array_values(array_intersect(array_keys($all_cols), $_GET['columnas'])) : array_keys($all_cols);

// Solo cargar datos si se va a exportar o si se aplicaron filtros
$datos = [];
$resumen = ['total' => 0, 'por_tipo' => [], 'por_nivel' => [], 'por_procedencia' => []];

$debe_cargar_datos = (
    isset($_GET['exportar']) || 
    isset($_GET['mostrar']) ||
    !empty($_GET['nivel']) || 
    !empty($_GET['tipo']) || 
    !empty($_GET['procedencia']) || 
    !empty($_GET['estado']) || 
    !empty($_GET['aula_funcional']) || 
    !empty($_GET['denominacion']) || 
    !empty($_GET['usuario_responsable']) || 
    !empty($_GET['fecha_inicio']) || 
    !empty($_GET['fecha_fin'])
);

if ($debe_cargar_datos) {
    // Agregar LIMIT para prevenir consultas muy grandes
    $limit_sql = ' LIMIT 5000';
    $query = "SELECT * FROM inventario $where_sql ORDER BY nivel, aula_funcional, denominacion$limit_sql";
    $stmt = $conn->prepare($query);
    $params = [];
    if ($where) {
        // Construir arreglo de parámetros en el mismo orden en que se añadieron las cláusulas WHERE
        if (!empty($_GET['nivel'])) $params[] = $_GET['nivel'];
        if (!empty($_GET['tipo'])) $params[] = $_GET['tipo'];
        if (!empty($_GET['procedencia'])) $params[] = $_GET['procedencia'];
        if (!empty($_GET['estado'])) $params[] = $_GET['estado'];
        if (!empty($_GET['aula_funcional'])) $params[] = $_GET['aula_funcional'];
        if (!empty($_GET['denominacion'])) $params[] = '%' . $_GET['denominacion'] . '%';
        if (!empty($_GET['usuario_responsable'])) $params[] = '%' . $_GET['usuario_responsable'] . '%';
        if (!empty($_GET['fecha_inicio'])) $params[] = $_GET['fecha_inicio'];
        if (!empty($_GET['fecha_fin'])) $params[] = $_GET['fecha_fin'];

        if (count($params) > 0) {
            $param_types = str_repeat('s', count($params)); // todos strings
            // bind_param requiere parámetros por referencia; usar call_user_func_array
            $bind_names = [];
            $bind_names[] = $param_types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_names[] = & $params[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_names);
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $datos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Calcular resumen estadístico
    $resumen = [
        'total' => count($datos),
        'por_tipo' => [],
        'por_nivel' => [],
        'por_procedencia' => []
    ];
    foreach ($datos as $fila) {
        $resumen['por_tipo'][$fila['tipo']] = ($resumen['por_tipo'][$fila['tipo']] ?? 0) + 1;
        $resumen['por_nivel'][$fila['nivel']] = ($resumen['por_nivel'][$fila['nivel']] ?? 0) + 1;
        $resumen['por_procedencia'][$fila['procedencia']] = ($resumen['por_procedencia'][$fila['procedencia']] ?? 0) + 1;
    }
}

// Exportar a Excel
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(array_map(function($k)use($all_cols){return $all_cols[$k];}, $cols), NULL, 'A1');
    foreach($datos as $i=>$fila) {
        $row = [];
        foreach($cols as $k) $row[] = array_key_exists($k, $fila) ? $fila[$k] : "";
        $sheet->fromArray($row, NULL, 'A'.($i+2));
    }
    // Pie de advertencia de uso interno
    $sheet->setCellValue('A'.($i+3), 'USO INTERNO: Prohibida su difusión o reproducción fuera de la institución.');
    $sheet->mergeCells('A'.($i+3).':'.$sheet->getHighestColumn().($i+3));
    $sheet->getStyle('A'.($i+3))->getFont()->setBold(true)->getColor()->setRGB('B71C1C');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="reporte_inventario.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
// Exportar a PDF (soporta agrupado: ?group=por_aula o ?group=por_item)
if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
    $export_cols = $cols;
    $group = $_GET['group'] ?? '';
    
    // Limitar cantidad de registros para evitar timeout
    // Para etiquetas con QR (por_item), el límite es mucho menor porque genera 1 QR por registro
    $max_registros = ($group === 'por_item') ? 100 : 1000;
    if (count($datos) > $max_registros) {
        $tipo_export = ($group === 'por_item') ? 'etiquetas con códigos QR' : 'PDF';
        die('Error: Demasiados registros para exportar como ' . $tipo_export . ' (' . count($datos) . ' registros). Por favor, aplica filtros para reducir la cantidad (máximo ' . $max_registros . ' registros). Para exportar más registros, usa el formato Excel.');
    }
    
    ob_start();
    try {
        if ($group === 'por_item') {
            // Etiquetas por item (una etiqueta por página)
            include __DIR__ . '/reporte_inventario_labels.php';
        } elseif ($group === 'por_aula') {
            // Usar la tabla pero agrupada por aula: una sección por aula en páginas separadas
            include __DIR__ . '/reporte_inventario_tabla.php';
            // nota: la plantilla imprimible respetará los THEAD/TFOOT y Dompdf paginará según contenido
        } else {
            include __DIR__ . '/reporte_inventario_tabla.php';
        }
        $html = ob_get_clean();
    } catch (Exception $e) {
        ob_end_clean();
        die('Error generando el contenido del PDF: ' . $e->getMessage());
    }
    
    // Intentar mitigar errores por falta de memoria y optimizar Dompdf:
    // - aumentar límite de memoria temporalmente
    // - habilitar subsetting de fuentes y parser HTML5
    // Nota: si esto no es suficiente, aumenta memory_limit en php.ini y/o divide el reporte en partes.
    @ini_set('memory_limit', '512M');
    set_time_limit(120); // 2 minutos máximo para generar PDF
    // Asegurar UTF-8 y usar una fuente que soporte acentos (DejaVu Sans)
    mb_internal_encoding('UTF-8');
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('enable_font_subsetting', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
        // Inyectar meta charset y estilo base para forzar DejaVu Sans en el HTML
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />'
            . '<style>body{font-family:"DejaVu Sans", Arial, sans-serif;}</style>'
            . $html;
        $dompdf->loadHtml($html);
    // Elegir orientación según el modo de exportación
    if ($group === 'por_item') {
        // Etiqueta por ítem: usar A7 portrait (tamaño pequeño) y márgenes reducidos
        // Dompdf soporta 'A7' como tamaño de papel
        $dompdf->setPaper('A7', 'portrait');
    } else {
        // Por aula o listado: landscape para mayor ancho
        $dompdf->setPaper('A4', 'landscape');
    }
    $dompdf->render();
    // Agregar numeración y pie en cada página (fecha y usuario)
    $canvas = $dompdf->get_canvas();
    $font = $dompdf->getFontMetrics()->get_font('DejaVu Sans', 'normal');
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    $footer_left = 'Generado: ' . date('Y-m-d H:i') . ' por Administrador';
    $footer_right = 'Página {PAGE_NUM} / {PAGE_COUNT}';
    // Posicionar pies: 20 puntos desde el borde izquierdo y 20 desde el derecho
    $canvas->page_text(20, $h - 24, $footer_left, $font, 9, array(0,0,0));
    $canvas->page_text($w - 120, $h - 24, $footer_right, $font, 9, array(0,0,0));
    $filename = 'reporte_inventario'.($group?"_{$group}":'').'.pdf';
    // Abrir en nueva pestaña en el navegador (no forzar descarga)
    $dompdf->stream($filename, ['Attachment' => 0]);
    exit;
}

// Exportar a CSV
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment;filename="reporte_inventario.csv"');
    header('Cache-Control: max-age=0');
    $output = fopen('php://output', 'w');
    // Escribir encabezados
    $encabezados = array_map(function($k) use ($all_cols) { return $all_cols[$k]; }, $cols);
    fputcsv($output, $encabezados);
    // Escribir datos
    foreach ($datos as $fila) {
        $row = [];
        foreach ($cols as $k) $row[] = array_key_exists($k, $fila) ? $fila[$k] : "";
        fputcsv($output, $row);
    }
    // Pie de advertencia de uso interno
    fputcsv($output, ["USO INTERNO: Prohibida su difusión o reproducción fuera de la institución."]);
    fclose($output);
    exit;
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inventario</title>
    <style>
        :root{ --fyared:#b71c1c; --fyablue:#1976d2; --accent:#ffb300; --panel-bg:#ffffff; --muted:#666; }
        body { font-family: Arial, sans-serif; margin: 18px; background: #fbfbfc; }
        main, form, input, select, label, .btn { color: #222 !important; }
        /* Paneles y filtros con contraste mayor */
        form, fieldset, .filtros {
            background: var(--panel-bg) !important;
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
            border-radius: 12px;
            border: 1px solid rgba(183,28,28,0.12);
            padding: 10px;
        }
        input, select, textarea {
            background: #fff !important;
            color: #222 !important;
            border: 1.6px solid rgba(0,0,0,0.12) !important;
            box-shadow: inset 0 1px 0 rgba(0,0,0,0.02);
            padding: 8px 10px;
            border-radius: 6px;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--fyared) !important; box-shadow: 0 4px 14px rgba(183,28,28,0.08); }
        label { color: var(--fyared) !important; font-weight: 700; }
        .btn { background: var(--fyared) !important; color: #fff !important; font-weight: 700; border-radius: 8px; padding: 8px 14px; border: none; cursor: pointer; }
        .btn.secondary { background: var(--accent); color:#222; }
        .btn.ghost { background: transparent; border:1.2px solid rgba(0,0,0,0.08); color:#222; }
        .btn:hover { filter:brightness(0.98); }
        .subtitulo, h3 { color: var(--fyared) !important; }
        /* Título principal más visible */
        .hero { display:flex; align-items:center; gap:20px; justify-content:center; background: linear-gradient(135deg, #8b0000 0%, var(--fyared) 40%, #e53935 100%); min-height:110px; margin-bottom:10px; box-shadow:0 8px 32px rgba(0,0,0,0.25), 0 2px 8px rgba(183,28,28,0.3); border-radius:14px; padding:16px; border-bottom:4px solid #ff6f00; position:relative; overflow:hidden; }
        .hero::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(circle at top right, rgba(255,111,0,0.2) 0%, transparent 60%);z-index:0}
        .hero > * {position:relative;z-index:1}
        .hero img{ height:85px; width:auto; background:#fff; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,0.35), 0 0 0 3px rgba(255,255,255,0.3); padding:10px; display:block; border:2px solid #ff6f00; transition:transform 0.3s; }
        .hero img:hover{transform:scale(1.08) rotate(2deg)}
        .site-title { display:inline-block; vertical-align:middle; font-size:2.1em; color:#fff; text-shadow:3px 3px 8px rgba(0,0,0,0.6), 0 0 30px rgba(255,111,0,0.4); margin:0; letter-spacing:0.8px; padding:10px 18px; background: linear-gradient(135deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.15) 100%); border-radius:12px; font-weight:900; border:2px solid rgba(255,255,255,0.2); backdrop-filter:blur(8px); }
        .hero .btn { margin-left:auto; background:#fff !important; color:var(--fyared) !important; font-weight:800; padding:10px 20px; box-shadow:0 4px 16px rgba(0,0,0,0.25); border:2px solid rgba(255,255,255,0.3); }
        .hero .btn:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(255,255,255,0.3)}
        /* Resumen y tabla */
        .summary-box { margin:18px auto 8px auto; padding:12px 14px; background:#fff; border-radius:10px; max-width:900px; box-shadow:0 4px 12px rgba(0,0,0,0.04); }
        .page-subtitle { text-align:center; color: #2b2b2b; margin-bottom:12px; font-weight:600; }
        table { border-collapse: separate; border-spacing: 0; width: 100%; margin-top: 12px; background: #fff !important; border-radius: 10px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); overflow: hidden; }
        th, td { border-bottom: 1px solid #f0eded; padding: 8px 8px; font-size: 0.96em; background: #fff !important; color: #222 !important; }
        th { background: var(--fyared) !important; color: #fff !important; position: sticky; top: 0; z-index: 2; }
        tr:nth-child(even) td { background: #fbfbfc !important; }
        tr:hover td { background: #fff3f3 !important; }
        /* Ajustes de UX para filtros y botones */
        .filtros label, .filtros fieldset { margin-bottom: 6px; }
        .filtros button { margin-top: 6px; }
        /* Responsive */
        @media (max-width: 900px) { .filtros { flex-direction: column; align-items: stretch; } table, th, td { font-size: 0.94em; } }
        @media (max-width: 600px) { .filtros { padding: 8px; } table, th, td { font-size: 0.9em; } th, td { padding: 6px 4px; } .site-title { font-size:1.25em; } }
        
        /* ===== ESTILOS DE IMPRESIÓN PROFESIONALES ===== */
        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm 6mm 15mm 6mm;
            }
            
            body {
                background: white !important;
                color: #000 !important;
                margin: 0;
                padding: 0;
                font-size: 8pt;
            }
            
            /* Ocultar elementos no imprimibles */
            .filtros, .btn, button, 
            a[href*="inventario_central"], 
            form input[type="submit"],
            form button,
            form select,
            form input[type="text"],
            form input[type="date"],
            form input[type="checkbox"],
            form fieldset,
            form label,
            .page-subtitle,
            div[style*="background:#fff3cd"] {
                display: none !important;
            }
            
            /* Encabezado del reporte - visible en cada página */
            .hero {
                display: flex !important;
                align-items: center;
                justify-content: space-between;
                background: white !important;
                border-bottom: 2px solid #b71c1c;
                padding: 4px 8px !important;
                margin: 0 0 6px 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                page-break-after: avoid;
                position: relative;
            }
            
            .hero::before {
                display: none !important;
            }
            
            .hero img {
                height: 40px !important;
                width: auto !important;
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                padding: 3px !important;
                border-radius: 3px !important;
                background: white !important;
            }
            
            .site-title {
                font-size: 14pt !important;
                color: #b71c1c !important;
                text-shadow: none !important;
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                font-weight: 900;
                letter-spacing: 0.3px;
            }
            
            /* Información del reporte */
            .print-header-info {
                display: block !important;
                text-align: right;
                font-size: 7.5pt;
                color: #444;
                margin-bottom: 4px;
                page-break-after: avoid;
            }
            
            /* Resumen estadístico para impresión */
            div[style*="margin:18px 0 8px 0"] {
                display: block !important;
                background: #f5f5f5 !important;
                border: 1px solid #ddd !important;
                border-radius: 3px !important;
                padding: 4px 6px !important;
                margin: 4px 0 !important;
                font-size: 7.5pt !important;
                box-shadow: none !important;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            
            /* Tabla optimizada para impresión con columnas compactas */
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                border: 1px solid #333 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                font-size: 6.5pt !important;
                page-break-inside: auto;
                table-layout: fixed !important;
            }
            
            thead {
                display: table-header-group !important;
                background: #b71c1c !important;
            }
            
            th {
                background: #b71c1c !important;
                color: white !important;
                border: 1px solid #333 !important;
                padding: 3px 2px !important;
                font-weight: bold !important;
                text-align: left !important;
                font-size: 7pt !important;
                position: relative !important;
                word-wrap: break-word !important;
                overflow: hidden;
                line-height: 1.1;
            }
            
            td {
                border: 1px solid #ccc !important;
                padding: 2px 2px !important;
                background: white !important;
                color: #000 !important;
                font-size: 6.5pt !important;
                vertical-align: top;
                word-wrap: break-word !important;
                overflow: hidden;
                line-height: 1.2;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            tr:nth-child(even) td {
                background: #f9f9f9 !important;
            }
            
            tr:hover td {
                background: inherit !important;
            }
            
            /* Ocultar columnas menos importantes por defecto para ahorrar espacio */
            /* Puedes comentar estas líneas si necesitas ver estas columnas */
            th:has(+ th + th + th + th + th + th + th + th + th),
            td:nth-child(10), /* Doc. Alta */
            th:nth-child(10),
            td:nth-child(11), /* Fecha Compra */
            th:nth-child(11),
            td:nth-child(12), /* N° Doc */
            th:nth-child(12),
            td:nth-child(15), /* Observaciones */
            th:nth-child(15),
            td:nth-child(17), /* Ubicación */
            th:nth-child(17),
            td:nth-child(18), /* Fecha Registro */
            th:nth-child(18) {
                display: none !important;
            }
            
            /* Ajuste de anchos de columnas principales para mejor distribución */
            th:nth-child(1), td:nth-child(1) { width: 6%; } /* Nivel */
            th:nth-child(2), td:nth-child(2) { width: 8%; } /* Aula */
            th:nth-child(3), td:nth-child(3) { width: 18%; } /* Denominación */
            th:nth-child(4), td:nth-child(4) { width: 4%; } /* Cantidad */
            th:nth-child(5), td:nth-child(5) { width: 10%; } /* Marca */
            th:nth-child(6), td:nth-child(6) { width: 10%; } /* Modelo */
            th:nth-child(7), td:nth-child(7) { width: 8%; } /* Tipo */
            th:nth-child(8), td:nth-child(8) { width: 6%; } /* Color */
            th:nth-child(9), td:nth-child(9) { width: 10%; } /* Serie */
            th:nth-child(13), td:nth-child(13) { width: 6%; } /* Estado */
            th:nth-child(14), td:nth-child(14) { width: 8%; } /* Procedencia */
            th:nth-child(16), td:nth-child(16) { width: 6%; } /* Usuario */
            
            /* ===== ESTILOS OPTIMIZADOS PARA REPORTE RÁPIDO (5 columnas) ===== */
            /* Cuando hay 5 o menos columnas, usar fuentes más grandes y mejor espaciado */
            table:has(th:nth-child(5):last-child) {
                font-size: 10pt !important;
            }
            table:has(th:nth-child(5):last-child) th {
                font-size: 11pt !important;
                padding: 6px 8px !important;
            }
            table:has(th:nth-child(5):last-child) td {
                font-size: 10pt !important;
                padding: 5px 8px !important;
            }
            /* Distribución ideal para reporte rápido: Aula (15%), Denominación (40%), Cantidad (10%), Estado (15%), Procedencia (20%) */
            table:has(th:nth-child(5):last-child) th:nth-child(1),
            table:has(th:nth-child(5):last-child) td:nth-child(1) { width: 15% !important; } /* Aula */
            table:has(th:nth-child(5):last-child) th:nth-child(2),
            table:has(th:nth-child(5):last-child) td:nth-child(2) { width: 40% !important; } /* Denominación */
            table:has(th:nth-child(5):last-child) th:nth-child(3),
            table:has(th:nth-child(5):last-child) td:nth-child(3) { width: 10% !important; text-align: center !important; } /* Cantidad */
            table:has(th:nth-child(5):last-child) th:nth-child(4),
            table:has(th:nth-child(5):last-child) td:nth-child(4) { width: 15% !important; } /* Estado */
            table:has(th:nth-child(5):last-child) th:nth-child(5),
            table:has(th:nth-child(5):last-child) td:nth-child(5) { width: 20% !important; } /* Procedencia */
            
            /* Pie de página en cada hoja */
            .print-footer {
                display: block !important;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 7pt;
                color: #666;
                border-top: 1px solid #ccc;
                padding: 3px 0;
                background: white;
            }
            
            /* Mensaje de información */
            td[colspan] {
                text-align: center !important;
                font-weight: bold !important;
                background: #fffde7 !important;
                color: #b71c1c !important;
                border: 2px solid #b71c1c !important;
                padding: 8px !important;
                font-size: 8pt !important;
            }
            
            /* Ocultar scrolls */
            div[style*="overflow-x:auto"],
            div[style*="overflow-y:auto"] {
                overflow: visible !important;
                max-height: none !important;
            }
            
            /* Alternativa: Si aún no caben las columnas, agregar clase .print-compact al body */
            body.print-compact table {
                font-size: 5.5pt !important;
            }
            body.print-compact th {
                font-size: 6pt !important;
                padding: 2px 1px !important;
            }
            body.print-compact td {
                font-size: 5.5pt !important;
                padding: 1px 1px !important;
            }
        }
    </style>
    <script>
        // Agregar información de impresión dinámica
        window.addEventListener('beforeprint', function() {
            // Crear encabezado de información si no existe
            if (!document.querySelector('.print-header-info')) {
                var info = document.createElement('div');
                info.className = 'print-header-info';
                info.innerHTML = '<strong>Fecha de impresión:</strong> ' + new Date().toLocaleString('es-PE') + 
                                ' | <strong>Usuario:</strong> Administrador | <strong>Nota:</strong> Algunas columnas pueden estar ocultas para ajustar a la página';
                document.querySelector('.hero').after(info);
            }
            
            // Crear pie de página si no existe
            if (!document.querySelector('.print-footer')) {
                var footer = document.createElement('div');
                footer.className = 'print-footer';
                footer.innerHTML = 'Colegio Fe y Alegría 44 - Reporte de Inventario - Documento de uso interno';
                document.body.appendChild(footer);
            }
            
            // Mostrar alerta si hay muchas columnas seleccionadas
            var columnCount = document.querySelectorAll('table th').length;
            if (columnCount > 12) {
                console.log('⚠️ SUGERENCIA: Hay ' + columnCount + ' columnas. Para mejor visualización, desmarque columnas innecesarias antes de imprimir.');
            }
        });
    </script>
</head>
<body>
    <div class="hero">
        <img src="logo_fya44.png" alt="Logo Fe y Alegría 44">
        <h2 class="site-title">Inventario del Colegio Fe y Alegría 44</h2>
        <a href="inventario_central.php" class="btn" style="margin-left:auto;">Regresar a Inventario</a>
    </div>
    <div class="page-subtitle">Reporte de bienes, fungibles y donaciones</div>
    <div style="text-align:center;color:#006400;margin-bottom:8px;font-weight:600;">Cambios aplicados: las columnas <b>Largo / Ancho / Alto</b> fueron eliminadas de los reportes. Última modificación del archivo: <?= date('Y-m-d H:i', filemtime(__FILE__)) ?></div>
    <form class="filtros" method="get" style="display:block;max-width:1200px;margin:0 auto 18px auto;" id="form_filtros">
        <div style="display:flex;flex-wrap:wrap;gap:18px 24px;align-items:flex-end;justify-content:center;">
            <fieldset style="border:1px solid #ccc;padding:8px 12px;border-radius:8px;min-width:180px;max-width:350px;flex:1 1 220px;">
                <legend style="font-size:0.98em;color:#b71c1c;">Columnas a exportar</legend>
                <?php foreach($all_cols as $k=>$v): ?>
                    <label style="display:inline-block;width:48%;margin-bottom:2px;">
                        <input type="checkbox" name="columnas[]" value="<?= $k ?>" class="col-checkbox" <?= in_array($k, $cols)?'checked':'' ?>> <?= $v ?>
                    </label>
                <?php endforeach; ?>
                <div style="margin-top:8px;display:flex;gap:6px;justify-content:space-between;">
                    <button type="button" onclick="seleccionarReporteRapido()" class="btn" style="background:#1976d2;font-size:0.85em;padding:5px 8px;">📄 Reporte Rápido</button>
                    <button type="button" onclick="seleccionarTodas()" class="btn ghost" style="font-size:0.85em;padding:5px 8px;">Todas</button>
                </div>
            </fieldset>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 18px;min-width:220px;flex:2 1 340px;">
                <label>Nivel:<br>
                    <select name="nivel" id="filtro_nivel">
                        <option value="">Todos</option>
                        <?php foreach ($niveles as $n) echo '<option value="'.$n.'"'.(@$_GET['nivel']==$n?' selected':'').'>'.$n.'</option>'; ?>
                    </select>
                </label>
                <label>Aula:<br>
                    <select name="aula_funcional" id="filtro_aula_funcional">
                        <option value="">Todas</option>
                        <?php foreach ($aulas as $a) echo '<option value="'.$a.'"'.(@$_GET['aula_funcional']==$a?' selected':'').'>'.$a.'</option>'; ?>
                    </select>
                    <br>
                    <button type="button" id="btn_qr_aula" class="btn" style="background:#388e3c;margin-top:6px;display:none;" onclick="generarQrAula()">Generar QR del aula</button>
                </label>
                <label>Tipo:<br>
                    <select name="tipo">
                        <option value="">Todos</option>
                        <?php foreach ($tipos as $t) echo '<option value="'.$t.'"'.(@$_GET['tipo']==$t?' selected':'').'>'.$t.'</option>'; ?>
                    </select>
                </label>
                <label>Estado:<br>
                    <select name="estado">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $e) echo '<option value="'.$e.'"'.(@$_GET['estado']==$e?' selected':'').'>'.$e.'</option>'; ?>
                    </select>
                </label>
                <label>Procedencia:<br>
                    <select name="procedencia">
                        <option value="">Todas</option>
                        <?php foreach ($procedencias as $p) echo '<option value="'.$p.'"'.(@$_GET['procedencia']==$p?' selected':'').'>'.$p.'</option>'; ?>
                    </select>
                </label>
                <label>Denominación:<br>
                    <input type="text" name="denominacion" value="<?= htmlspecialchars(@$_GET['denominacion']??'') ?>" placeholder="Buscar artículo">
                </label>
                <label>Usuario Responsable:<br>
                    <input type="text" name="usuario_responsable" value="<?= htmlspecialchars(@$_GET['usuario_responsable']??'') ?>" placeholder="Buscar usuario">
                </label>
                <label>Fecha inicio:<br>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars(@$_GET['fecha_inicio']??'') ?>">
                </label>
                <label>Fecha fin:<br>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars(@$_GET['fecha_fin']??'') ?>">
                </label>
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:12px;">
            <button class="btn" type="submit" name="mostrar" value="1" style="background:#388e3c;">Mostrar</button>
            <button class="btn" type="button" onclick="window.location.href='reporte_inventario.php?aulas_status=1'" style="background:#6a1b9a;">Estado por aula</button>
            <button class="btn" type="button" onclick="window.location.href=window.location.pathname;" style="background:#ffb300;color:#222;font-weight:bold;">Limpiar filtros</button>
            <button class="btn" type="submit" name="exportar" value="excel">Exportar Excel</button>
            <!-- Selector para agrupar/etiquetas antes de exportar PDF -->
            <label style="display:inline-block;margin-left:6px;">
                Modo PDF:<br>
                <select name="group" id="select_pdf_mode" style="padding:6px;border-radius:6px;border:1px solid #ccc;">
                    <option value="">Estándar</option>
                    <option value="por_aula" <?= (isset($_GET['group']) && $_GET['group']==='por_aula')?'selected':'' ?>>Por aula</option>
                    <option value="por_item" <?= (isset($_GET['group']) && $_GET['group']==='por_item')?'selected':'' ?>>Etiquetas con QR (una por página)</option>
                </select>
            </label>
            <button class="btn" type="submit" name="exportar" value="pdf">Exportar PDF</button>
            <button class="btn" type="submit" name="exportar" value="csv">Exportar CSV</button>
            <button class="btn" type="button" onclick="window.print()" style="background:#1976d2;">Imprimir reporte</button>
        </div>
        <div style="text-align:center;margin-top:8px;padding:8px;background:#fff3cd;border-radius:6px;max-width:800px;margin-left:auto;margin-right:auto;font-size:0.92em;">
            <strong>⚠️ Límites de exportación:</strong> PDF estándar: máx. 1000 registros | <span style="color:#d32f2f;">Etiquetas con QR: máx. 100 registros</span> (proceso intensivo) | Excel: máx. 5000 registros
        </div>
        <div style="text-align:center;margin-top:8px;padding:8px;background:#e3f2fd;border-left:4px solid #1976d2;border-radius:6px;max-width:800px;margin-left:auto;margin-right:auto;font-size:0.9em;">
            <strong>💡 Reporte Rápido para imprimir:</strong> Haga clic en el botón <strong>"📄 Reporte Rápido"</strong> para seleccionar automáticamente solo las columnas esenciales: <span style="color:#1976d2;font-weight:600;">Aula, Denominación, Cantidad, Estado y Procedencia</span>. Ideal para impresión clara y legible.
        </div>
        <script>
        // Seleccionar solo columnas esenciales para reporte rápido
        function seleccionarReporteRapido() {
            const columnasRapidas = ['aula_funcional', 'denominacion', 'cantidad', 'estado', 'procedencia'];
            const checkboxes = document.querySelectorAll('.col-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = columnasRapidas.includes(cb.value);
            });
        }
        
        // Seleccionar todas las columnas
        function seleccionarTodas() {
            const checkboxes = document.querySelectorAll('.col-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = true;
            });
        }
        
        // Mostrar el botón QR solo si hay aula seleccionada
        function generarQrAula() {
            const aula = document.getElementById('filtro_aula_funcional').value;
            if (aula) {
                window.open('generar_qr_aula.php?aula=' + encodeURIComponent(aula), '_blank');
            }
        }
        const selectAula = document.getElementById('filtro_aula_funcional');
        const btnQrAula = document.getElementById('btn_qr_aula');
        function toggleQrBtn() {
            if (selectAula.value) {
                btnQrAula.style.display = 'inline-block';
            } else {
                btnQrAula.style.display = 'none';
            }
        }
        selectAula.addEventListener('change', toggleQrBtn);
        window.addEventListener('DOMContentLoaded', toggleQrBtn);
        </script>
    </form>



    <!-- Resumen estadístico -->
    <div style="margin:18px 0 8px 0; padding:10px 12px; background:#f8f8f8; border-radius:8px; max-width:900px; margin-left:auto; margin-right:auto;">
        <b>Total de bienes:</b> <?= $resumen['total'] ?> &nbsp;|
        <b>Por tipo:</b>
        <?php foreach($resumen['por_tipo'] as $k=>$v) echo "$k: $v &nbsp;"; ?>|
        <b>Por nivel:</b>
        <?php foreach($resumen['por_nivel'] as $k=>$v) echo "$k: $v &nbsp;"; ?>|
        <b>Por procedencia:</b>
        <?php foreach($resumen['por_procedencia'] as $k=>$v) echo "$k: $v &nbsp;"; ?>
    </div>
    <div style="overflow-x:auto; max-height: 60vh; overflow-y:auto;">
    <table>
        <tr>
            <?php foreach($cols as $col) echo '<th>'.$all_cols[$col].'</th>'; ?>
        </tr>
        <?php if (count($datos) === 0): ?>
        <tr><td colspan="<?= count($cols) ?>" style="text-align:center;color:#b71c1c;font-weight:bold;background:#fffde7;">No hay resultados para los filtros seleccionados.<br>Prueba con otros filtros o haz clic en <span style='color:#ffb300;'>Limpiar filtros</span> para ver todo el inventario.</td></tr>
        <?php else: ?>
        <?php foreach($datos as $fila): ?>
        <tr>
            <?php foreach($cols as $col) echo '<td>'.htmlspecialchars(array_key_exists($col, $fila) ? $fila[$col] : "").'</td>'; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </table>
    </div>
        <div style="margin-top:18px;font-size:0.95em;color:#444;text-align:right;">
            Reporte generado el <?= date('Y-m-d H:i') ?> por <b>Administrador</b>
        </div>

<script>
// Selector de aulas dependiente del nivel
const aulasPorNivel = <?php echo json_encode($aulas_json, JSON_UNESCAPED_UNICODE); ?>;
document.getElementById('filtro_nivel').addEventListener('change', function() {
    const nivel = this.value;
    const selectAula = document.getElementById('filtro_aula_funcional');
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
});
// Si ya hay un nivel seleccionado al cargar, actualizar aulas
window.addEventListener('DOMContentLoaded', function() {
    const nivel = document.getElementById('filtro_nivel').value;
    if (nivel) {
        const event = new Event('change');
        document.getElementById('filtro_nivel').dispatchEvent(event);
        // Seleccionar aula si estaba seleccionada
        const aulaSel = "<?= isset($_GET['aula_funcional']) ? $_GET['aula_funcional'] : '' ?>";
        if (aulaSel) {
            document.getElementById('filtro_aula_funcional').value = aulaSel;
        }
    }
});
</script>
<footer style="background:linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);box-shadow:0 -6px 24px rgba(0,0,0,0.2);padding:30px 20px 20px 20px;margin-top:60px;text-align:center;border-top:4px solid #ff6f00;">
    <div style="max-width:1200px;margin:0 auto;">
        <div style="font-size:1.4em;font-weight:900;background:linear-gradient(135deg, #ff6f00 0%, #ff9800 50%, #ffc107 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:12px;letter-spacing:1px;">MAX SYSTEM</div>
        <div style="color:#e0e0e0;font-size:1.05em;margin-bottom:8px;font-weight:600;">Sistema de Inventario Inteligente</div>
        <div style="color:#b0b0b0;font-size:0.95em;margin-bottom:10px;">Colegio Fe y Alegría 44 — Reportes y Análisis</div>
        <div style="color:#888;font-size:0.9em;border-top:1px solid #444;padding-top:12px;margin-top:12px;">&copy; <?= date('Y') ?> Todos los derechos reservados | Desarrollado con <span style="color:#ff6f00;">❤</span> por Max System</div>
    </div>
</footer>
</body>
</html>

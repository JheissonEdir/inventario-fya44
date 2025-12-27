<?php
$conn = new mysqli('localhost', 'root', '', 'inventario_escolar');
$sql = "SELECT aula_funcional, COUNT(*) as total FROM inventario WHERE usuario_responsable LIKE '%Yudit%' AND nivel='Secundaria' GROUP BY aula_funcional ORDER BY aula_funcional";
$result = $conn->query($sql);
echo "Resumen de registros de Yudit Rojas Rodriguez:\n\n";
$total_general = 0;
while($row = $result->fetch_assoc()) {
    echo "- {$row['aula_funcional']}: {$row['total']} registros\n";
    $total_general += $row['total'];
}
echo "\nTOTAL: $total_general registros\n";
$conn->close();

<?php
// Test para verificar la normalización de headers

$encabezados = ['Nivel','Aula Funcional','Denominación','Marca'];

$normalized = [];
foreach ($encabezados as $h) {
    $k = mb_strtolower(trim($h));
    echo "Original: '$h' -> Lowercase: '$k'\n";
    
    $k = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $k);
    echo "Después de reemplazar acentos: '$k'\n";
    
    $k = preg_replace('/[^a-z0-9_]/u', '_', $k);
    echo "Después de preg_replace: '$k'\n";
    
    if ($k === 'aula') $k = 'aula_funcional';
    if ($k === 'denominacion' || $k === 'denominaci_n') $k = 'denominacion';
    
    echo "Final: '$k'\n\n";
    $normalized[] = $k;
}

echo "Array normalizado:\n";
print_r($normalized);

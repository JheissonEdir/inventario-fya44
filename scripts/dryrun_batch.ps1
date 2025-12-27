# dryrun_batch.ps1
# Ejecuta dry-run en lote para todos los CSVs en una carpeta y guarda reportes JSON.
# Uso: .\dryrun_batch.ps1 -Folder "C:\ruta\a\csvs" -Url "http://localhost/Inventario%20Escolar/inventario_central.php" -Anio 2025 -Token ""
param(
    [Parameter(Mandatory=$true)] [string] $Folder,
    [Parameter(Mandatory=$true)] [string] $Url,
    [int] $Anio = 2025,
    [string] $Token = ''
)
if (!(Test-Path $Folder)) { Write-Error "Carpeta no encontrada: $Folder"; exit 1 }
$files = Get-ChildItem -Path $Folder -Filter *.csv
if ($files.Count -eq 0) { Write-Host "No hay archivos CSV en $Folder"; exit 0 }
$outDir = Join-Path $Folder "dryrun_reports"
if (!(Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }
foreach ($f in $files) {
    $name = $f.Name
    Write-Host "Procesando: $name ..."
    $form = @{ 'anio' = $Anio; 'dry_run' = '1' }
    if ($Token -ne '') { $form['sync_token'] = $Token }
    $response = try {
        Invoke-RestMethod -Uri $Url -Method Post -InFile $f.FullName -ContentType 'multipart/form-data' -Body $form -ErrorAction Stop
    } catch {
        Write-Warning "Error en $name: $_"
        continue
    }
    $outFile = Join-Path $outDir ($name + '.json')
    $json = $response | ConvertTo-Json -Depth 6
    $json | Out-File -FilePath $outFile -Encoding UTF8
    Write-Host "Reporte guardado: $outFile"
}
Write-Host "Batch completado."
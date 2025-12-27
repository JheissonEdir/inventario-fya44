@echo off
REM Script para actualizar aulas en el HTML y subir a GitHub automáticamente
cd /d "%~dp0"
python actualizar_aulas_en_html.py
if %errorlevel% neq 0 (
    echo Error al ejecutar el script Python.
    pause
    exit /b %errorlevel%
)
git add inventario_local.html
if %errorlevel% neq 0 (
    echo Error al agregar archivo a git.
    pause
    exit /b %errorlevel%
)
git commit -m "Sincronizar aulas desde aulas.json"
git push
pause

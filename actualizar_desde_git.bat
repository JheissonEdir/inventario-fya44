actua@echo off
REM Script para actualizar el repositorio local desde GitHub (pull)
cd /d "%~dp0"
git pull origin main
pause

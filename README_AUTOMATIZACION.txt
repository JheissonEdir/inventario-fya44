# Manual de Automatización para Inventario Escolar

Este manual explica cómo utilizar los scripts de automatización incluidos en este proyecto para mantener sincronizada la lista de aulas entre el archivo `aulas.json` y la página `inventario_local.html`, así como para actualizar el repositorio en GitHub.

## Requisitos previos

- Tener **Python** instalado y agregado a la variable de entorno PATH.
- Tener **Git** instalado y agregado a la variable de entorno PATH.
- Tener permisos para ejecutar archivos `.bat` en tu sistema.

## Archivos importantes

- `aulas.json`: Contiene la lista de aulas por nivel educativo.
- `inventario_local.html`: Página web local que utiliza la lista de aulas.
- `actualizar_aulas_en_html.py`: Script Python que actualiza la variable `aulasData` en el HTML usando los datos de `aulas.json`.
- `actualizar_todo.bat`: Script por lotes que automatiza la actualización y subida a GitHub.
- `actualizar_desde_git.bat`: Script por lotes para descargar los últimos cambios desde GitHub.

## ¿Cómo actualizar la lista de aulas en la web?

1. **Edita** el archivo `aulas.json` desde la interfaz central o manualmente.
2. **Ejecuta** el archivo `actualizar_todo.bat` (doble clic o desde PowerShell/cmd):
   - Este script ejecuta el Python para actualizar el HTML y luego hace commit y push a GitHub.
3. **Verifica** que no haya errores en la consola. Si todo sale bien, los cambios estarán en GitHub y la web local.

## ¿Cómo descargar los últimos cambios de GitHub?

- Ejecuta `actualizar_desde_git.bat` para traer los cambios más recientes del repositorio remoto.

## Solución de problemas

- **Python no se reconoce**: Instala Python desde https://www.python.org/ y reinicia tu PC.
- **Git no se reconoce**: Instala Git desde https://git-scm.com/ y reinicia tu PC.
- **Permisos**: Si Windows bloquea la ejecución, haz clic derecho en el `.bat` y selecciona "Ejecutar como administrador".
- **Conflictos de Git**: Si hay conflictos, resuélvelos manualmente y repite el proceso.

## Notas adicionales

- Puedes editar `aulas.json` desde la interfaz central (PHP) o manualmente.
- El script Python solo actualiza la variable `aulasData` en el HTML, no modifica otras partes del archivo.
- Si tienes dudas, revisa la consola para mensajes de error o consulta a tu administrador.

---

**Inventario Escolar - Automatización de aulas**
17/11/2025

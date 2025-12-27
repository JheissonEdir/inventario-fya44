import json

# Ruta de los archivos
AULAS_JSON = 'aulas.json'
HTML = 'inventario_local.html'

# Leer aulas.json
with open(AULAS_JSON, encoding='utf-8') as f:
    aulas_data = json.load(f)

# Leer HTML
with open(HTML, encoding='utf-8') as f:
    html = f.read()

# Buscar el inicio de la variable aulasData
import re
pattern = r'(const aulasData = )\{[\s\S]*?\};'
reemplazo = f"const aulasData = {json.dumps(aulas_data, ensure_ascii=False, indent=2)};"

nuevo_html, n = re.subn(pattern, reemplazo, html)

if n == 0:
    print('No se encontró la variable aulasData en el HTML.')
else:
    with open(HTML, 'w', encoding='utf-8') as f:
        f.write(nuevo_html)
    print('¡Listo! La lista de aulas fue actualizada en inventario_local.html.')

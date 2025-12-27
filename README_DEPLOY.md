Instrucciones de despliegue para entorno cPanel (versión resumida)

Resumen
- `inventario_local.php` ahora usa autenticación simple (archivo `users.json`) y guarda inventario local por usuario en `data_local_{usuario}.json`.
- Hay un botón "Enviar a inventario central" que envía el CSV al servidor central (`inventario_central.php`) mediante una petición HTTP (cURL) autenticada con un token.
- `inventario_central.php` acepta importaciones remotas sólo si el `sync_token` enviado coincide con el valor en `sync_token.txt`.

Pasos recomendados antes de subir al cPanel gratuito

1) Revisar requisitos PHP
- Habilitar/extensiones: `curl`, `json`, `mysqli`. En cPanel generalmente están habilitadas.
- Si su hosting no permite cURL en salida HTTP, la sincronización no funcionará. En ese caso exporte CSV manualmente y cárguelo en el central.

2) Configurar `inventario_local.php`
- Abra `inventario_local.php` y modifique al inicio las constantes:
  - `CENTRAL_URL` -> URL pública donde está `inventario_central.php` (https si está disponible).
  - `SYNC_TOKEN` -> No obligatorio si central usa `sync_token.txt`, pero puede definir un token temporal aquí para pruebas.
    - `SYNC_TOKEN` -> No obligatorio si central usa `sync_token.txt`, pero puede definir un token temporal aquí para pruebas.

3) Crear `users.json` (en la misma carpeta)
- Formato ejemplo (texto plano para despliegue rápido):

[
  {"user":"juan","pass":"clave123"},
  {"user":"maria","pass":"otraClave"}
]

- Recomendación: en producción almacene hashes y ponga `users.json` fuera de `public_html` si es posible.

4) Usuarios administrativos (protección de la interfaz central)
- Para proteger la interfaz central sin depender de cPanel, el proyecto incluye un login PHP sencillo: `admin_users.json` contiene usuarios (por defecto en texto plano). Antes de publicar debe cambiar las claves.
- Rutas nuevas relacionadas:
  - `login_central.php` : formulario de acceso para la interfaz central.
  - `logout_central.php` : cerrar sesión.
  - `admin_users.json` : archivo con usuarios administrativos. Ejemplo:

[
  {"user":"admin","pass":"cambioSeguro"}
]

Recomendación: cambiar la contraseña por una fuerte. En despliegue seguro reemplace el almacenamiento por `password_hash` y coloque `admin_users.json` fuera de `public_html` si es posible.

4) Crear `sync_token.txt` en la carpeta del proyecto (servidor central)
- Este archivo debe contener una sola línea con el token secreto que compartirás con las instalaciones locales.
- Ejemplo:
  CAMBIA_A_UN_TOKEN_MUY_SEGURO

- Permisos: 600 o similar. No lo exponga al público.

5) Permisos y seguridad
- Asegure que `data_local_{usuario}.json` y `users.json` no sean listables públicamente.
- Si su cPanel permite, coloque `users.json` y `sync_token.txt` fuera de `public_html`.

6) Flujo de uso
- Crear usuarios en `users.json` (o administrar fuera de webroot).
- Los usuarios ingresan en `inventario_local.php` con su usuario/clave.
- Agregan items; el inventario se guarda en `data_local_{usuario}.json`.
- Cuando estén listos, presionan "Enviar a inventario central". El servidor local generará un CSV y lo enviará al `CENTRAL_URL`.
- `inventario_central.php` validará `sync_token` y agregará los bienes al inventario del año indicado (campo `anio` que se envía con la petición). Si el año no existe lo creará.

7) Problemas comunes y soluciones
- cURL deshabilitado: la sincronización falla; en su lugar use el botón "Exportar a CSV" y suba el archivo manualmente desde la interfaz de `inventario_central.php`.
- Permisos: si no puede escribir archivos JSON, cambie permisos del directorio pero evite 777.

¿Quiere que cree los archivos `users.json` y `sync_token.txt` de ejemplo en el repo (con valores de ejemplo), o prefiere que los cree usted en el servidor por seguridad? Si desea, los creo con valores de ejemplo que deberá cambiar antes de publicar.

--

Sección adicional: creación segura de administradores

- `make_admin.php`: se incluye un helper para crear/actualizar usuarios administrativos usando `password_hash`.
  - Uso web: abrir `make_admin.php` en el navegador y crear usuario/clave.
  - Uso CLI (si tiene acceso SSH/terminal en el servidor):

```bash
php make_admin.php admin MiClaveSegura
```

Al usar `make_admin.php` el archivo `admin_users.json` se actualizará con la clave en forma de `pass_hash` (bcrypt u otra implementación segura según la versión de PHP). Tras esto `login_central.php` acepta `pass_hash` y también actualiza automáticamente entradas antiguas que usen `pass` en texto plano.

Recomendación: después de crear los administradores con `make_admin.php`, elimine cualquier entrada que contenga `pass` en texto plano o mueva `admin_users.json` fuera de la carpeta pública si el hosting lo permite.

--

Sección adicional: registro y auditoría de sincronizaciones

- `sync_log.json`: archivo que guarda un historial de sincronizaciones recibidas por `inventario_central.php` (timestamp, hash de archivo, sync_id, año, inventario_id, importados, duplicados, source_user).
- Interfaz administrativa: si ha iniciado sesión como administrador, puede ver el historial desde la interfaz central con el botón "Historial Sync" o accediendo a:

```
inventario_central.php?sync_log=1
```

Este endpoint renderiza una tabla HTML con el resumen de cada envío. Recomendación: mover `sync_log.json` fuera de la carpeta pública cuando sea posible, o restringir su acceso mediante `.htaccess`.

Mover el log a la base de datos
- Si desea migrar `sync_log.json` a la base de datos (tabla `syncs`) para búsquedas y filtrado más robustos, hay un script incluido `migrate_sync_log.php` que crea la tabla y migra los registros.

Uso CLI para migrar:

```powershell
php migrate_sync_log.php
```

Esquema recomendado (SQL):

```sql
CREATE TABLE IF NOT EXISTS syncs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME,
  sync_id VARCHAR(255),
  file_hash VARCHAR(255),
  anio INT DEFAULT NULL,
  inventario_id INT DEFAULT NULL,
  importados INT DEFAULT 0,
  duplicados INT DEFAULT 0,
  source_user VARCHAR(255),
  uploader_ip VARCHAR(45)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Si la tabla `syncs` existe, `inventario_central.php` intentará registrar nuevas sincronizaciones también en la tabla además del `sync_log.json`.

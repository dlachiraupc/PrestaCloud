# Despliegue de PrestaCloud

PrestaCloud usa el **mismo hosting y el mismo mecanismo** que EmpresasCloud (ver el `DEPLOY.md` del ERP): cPanel con PHP 8.2 y
**MySQL 5.7**, SSH por el puerto 2222 con clave de despliegue, GitHub Actions, migraciones numeradas e idempotentes y un backup
antes de cada migración. Es un servicio **hermano** del ERP: repositorio, carpeta y base de datos propios. Nunca toca las carpetas del ERP.

## Entornos

| Rama      | Entorno        | Dominio                           | Carpeta en el hosting            | Base de datos (cPanel)   | Flujo |
|-----------|----------------|-----------------------------------|----------------------------------|--------------------------|-------|
| `develop` | Desarrollo     | `prestamosdev.empresascloud.com`  | `~/prestamosdev.cloudbusines.com`| `cloudbus_prestamosdev`  | `deploy-dev.yml` |
| `staging` | QA             | `prestamosqa.empresascloud.com`   | `~/prestamosqa.cloudbusines.com` | `cloudbus_prestamosqa`   | `deploy-qa.yml`  |
| `main`    | Producción     | `prestamos.empresascloud.com`     | `~/prestamos.cloudbusines.com`   | `cloudbus_prestamos`     | **no habilitado todavía** |

Flujo: `rama de trabajo → develop (dev) → staging (QA) → validar → main (producción)`. Los dos despliegues corren primero
`pruebas.yml` (sintaxis, migraciones en MySQL 5.7, PHPUnit); si algo falla, **no se despliega**.
Los nombres de dominio y de base son una propuesta: si cambian, se ajustan en `deploy/deploy.sh` y `deploy/verificar-publicacion.sh`.

## Qué hace cada despliegue (`deploy/deploy.sh`)

1. Comprueba que la carpeta del servidor tiene su `.env` y que **no** es una instalación del ERP.
2. Sube `servidor.sh` y las migraciones a `~/pc-deploy/<entorno>/<run>/`.
3. `servidor.sh migrar`: backup comprimido en `~/backups_prestacloud/` (15 últimos), aplica las migraciones pendientes y las anota
   en `pc_migraciones`. Si una falla, el código nuevo **no** se copia.
4. `rsync --delete` del código, con la lista protegida de `deploy/protegidos.sh`: nunca se tocan `.env`, `storage/`, `sesiones/`,
   el bloque de PHP de cPanel en los `.htaccess`, ni `.well-known`, `cgi-bin`, `error_log`, `.user.ini`, `php.ini`.
   `deploy/ci/probar-rsync.sh` lo prueba en cada ejecución del CI.
5. `deploy/verificar-publicacion.sh`: portada y `salud.php` (200, base de datos y núcleo de cálculo en ese servidor, PHP 8.2) y que
   `.env`, `src/`, `vendor/`, `migrations/` y `tests/` no se sirvan.

## Cómo se accede al hosting (llaves)

El acceso por SSH usa una **llave de despliegue** (par público/privado):

- La parte **pública** ya está autorizada en cPanel (*SSH Access*) para el ERP. Es la misma cuenta del hosting, así que sirve también
  para PrestaCloud: no hay que autorizar nada nuevo.
- La parte **privada** está solo en los secretos de GitHub (`SSH_KEY`). GitHub no permite leerla después de guardarla, por eso hay que
  copiarla desde su origen (donde se generó) al repositorio de PrestaCloud, junto con `SSH_HOST`, `SSH_PORT` y `SSH_USER`.
- Ninguna de estas credenciales se guarda en el repositorio ni en la documentación.

## Preparación de un entorno nuevo (una sola vez por entorno)

Lo hace Daniel: yo no tengo ni debo tener las credenciales del hosting ni la clave de despliegue.

1. **cPanel → Subdominios**: crear el subdominio (p. ej. `prestamosqa`) con **raíz del documento en la subcarpeta `public/`** de su carpeta
   (`prestamosqa.cloudbusines.com/public`). Si el ERP tiene un subdominio comodín, estos hay que crearlos de forma explícita.
2. **cPanel → MySQL Databases**: crear la base y su usuario con todos los privilegios (MySQL 5.7).
3. **cPanel → Select PHP Version**: PHP **8.2** para ese subdominio.
4. **`.env`** en la carpeta del entorno (permisos 600), a partir de `prestacloud-app/.env.example`: `APP_ENV=qa`, `APP_URL`, `DB_HOST=localhost`,
   `DB_NAME`, `DB_USER`, `DB_PASS`.
5. **GitHub → Settings → Secrets and variables → Actions**: `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_KEY` (los mismos valores que el ERP).
6. Crear las ramas `develop` y `staging` en el repositorio. El primer `push` a cada una despliega.

## Producción

No está habilitada. Cuando el piloto esté listo se agrega `deploy-prod.yml`, `ENTORNO=prod` en `deploy.sh` y el respaldo diario
(`respaldo-diario.sh`, como en el ERP). El `.env` de producción lo crea siempre Daniel.

## Reversión

Código: `git revert <commit>` y `push` a la rama del entorno. Las migraciones **no se deshacen** al volver al código anterior: para los datos
se restaura el backup `~/backups_prestacloud/<entorno>_<base>_<fecha>.sql.gz` (el estado justo antes de migrar), con un archivo de credenciales
temporal, igual que en el ERP (§3.2 de su `DEPLOY.md`).

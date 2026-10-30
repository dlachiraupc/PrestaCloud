# Retomar PrestaCloud en otro equipo

Esta guía es la memoria del proyecto: el equipo nuevo no tiene la conversación ni la memoria de Claude Code del anterior.
Si trabajas con Claude, empieza con: *«lee CONTINUAR.md, README.md, DEPLOY.md y DOCUMENTOS/ y dime qué sigue»*.

## 1. Poner el proyecto a andar

Hacen falta **Git**, **Docker Desktop** y **Node 20+** (solo para `tools/`). PHP y Composer no se instalan: corren en Docker.

```bash
git clone https://github.com/dlachiraupc/PrestaCloud.git && cd PrestaCloud
git checkout develop
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app vendor/bin/phpunit
```

Resultado esperado al cierre de la Fase 0: todo en verde, con las pruebas de base de datos omitidas (se activan como indica el `README.md`;
con MySQL 5.7 son 70 pruebas y 1185 aserciones, 1 omitida a propósito).

## 2. Qué NO viaja con git (y cómo llevarlo)

| Qué | Por qué no está en GitHub | Cómo llevarlo |
|---|---|---|
| **Llave SSH de despliegue** (`id_sysventasweb`) y su contraseña | Es privada y da acceso al hosting | Copiarla por un medio seguro (gestor de contraseñas o memoria USB cifrada) a `~/.ssh/` del equipo nuevo, o generar una llave nueva y autorizarla en cPanel → *SSH Access*. Nunca por correo, chat ni repositorio. |
| **`.env` de dev y QA** (contraseñas de base de datos) | Llevan contraseñas | Si las bases aún no existen en cPanel, no hay nada que copiar: `node tools/generar_env.mjs` crea unas nuevas en `entornos/` (git la ignora). Si ya existen, copiar `dev.env` y `qa.env` por un medio seguro desde `C:\Users\Administrador\prestacloud-entornos\`. |
| **Secretos de GitHub Actions** (`SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_KEY`) | Viven en GitHub, no en el equipo | No hay que mover nada. |
| **Libro `PRESTAMOS_v13.xlsx`** | Tiene clientes reales (Ley N.º 29733) | Ya está en OneDrive. Solo hace falta para volver a generar `tests/Fixtures/v13_golden.json`, que ya está versionado. |

## 3. Estado

**Hecho (Fase 0):** motor de cálculo en PHP puro con reglas por cuenta (`config_*`), paridad celda por celda con el Excel v13, esquema MySQL 5.7
multi-cuenta (`tenant_id` en toda tabla de negocio), scripts de despliegue y CI en verde. Los documentos están en `DOCUMENTOS/`:
`Plan_Fases_y_Validacion.md`, `REGLAS_DE_NEGOCIO.md` y el mockup navegable (`Mockup_PrestaCloud.html`, ábrelo en el navegador).

**Pendiente, en este orden:**

1. **Hosting (Daniel):** crear los subdominios `prestamosdev` y `prestamosqa`, sus bases y PHP 8.2, subir los `.env` y cargar los secretos de
   GitHub (pasos en `DEPLOY.md`, «Preparación de un entorno nuevo»). Alternativa: cargar la llave en el agente SSH (`ssh-add`) para que Claude lo haga por SSH.
2. **Repositorio privado (Daniel):** hoy es público. Settings → General → *Change visibility*.
3. **Publicar y verificar:** un `push` a `develop` y a `staging` despliega; comprobar con `deploy/verificar-publicacion.sh dev` y `… qa`.
4. **Aprobar reglas y esquema (Daniel):** `REGLAS_DE_NEGOCIO.md` y `migration_001_esquema.sql`. Decisiones abiertas en el §9 de las reglas;
   la más urgente es si cambiar la hora máxima reclasifica los pagos pasados.
5. **Fase 1 (MVP de uso propio):** acceso y `tenant_id` obligatorio en toda consulta → configuración de reglas por cliente → clientes y préstamos con
   simulador y TEA → cobranza del día y pagos con reintento seguro → caja con arqueo y panel → migración del v13 → 10 días en paralelo con el Excel.

## 4. Reglas del proyecto

- Las reglas de negocio son **datos de cada cuenta**; el motor no trae ninguna propia. Las del v13 son solo una fixture de pruebas.
- El ERP es un proyecto **hermano** (otra carpeta y otro repositorio). Se usa como referencia (cola de correos, respaldo con `rclone`, CSRF,
  modo solo lectura) y **no se modifica ni se despliega desde aquí**.
- Nunca al repositorio: `.env`, libros de Excel, volcados de base de datos, datos de clientes reales ni credenciales de ningún tipo.
- Linux distingue mayúsculas: los nombres de carpeta deben coincidir exactamente con el espacio de nombres (`tests/Fixtures`, no `fixtures`).

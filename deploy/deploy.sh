#!/bin/bash
# ============================================================
# PrestaCloud — Deploy por SSH al hosting (cPanel), mismo mecanismo que EmpresasCloud.
#
# Corre en GitHub Actions (o en un equipo con la clave SSH autorizada), desde la raíz del repositorio
# y con las dependencias composer ya instaladas (composer install --no-dev):
#
#   ENTORNO=dev SSH_HOST=... SSH_PORT=2222 SSH_USER=... SSH_KEY_FILE=... bash deploy/deploy.sh
#   ENTORNO=qa  SSH_HOST=... SSH_PORT=2222 SSH_USER=... SSH_KEY_FILE=... bash deploy/deploy.sh
#
# Orden (si un paso falla, los siguientes no se ejecutan):
#   1. Backup de la base de datos y migraciones pendientes (deploy/servidor.sh)
#   2. Código de la aplicación
#
# Nunca se tocan en el servidor: .env, storage/, sesiones/ ni los archivos de cPanel (bloque de PHP del
# .htaccess, .well-known, cgi-bin, error_log). Producción NO está habilitada todavía.
# ============================================================
set -euo pipefail

ENTORNO="${ENTORNO:-qa}"
SSH_PORT="${SSH_PORT:-2222}"
: "${SSH_HOST:?Falta SSH_HOST}" "${SSH_USER:?Falta SSH_USER}"

# Carpetas físicas del hosting (conservan el prefijo cloudbusines, como las del ERP) y dominio público.
case "$ENTORNO" in
    dev) APP_DIR="prestamosdev.cloudbusines.com"; DOMINIO="prestamosdev.empresascloud.com" ;;
    qa)  APP_DIR="prestamosqa.cloudbusines.com";  DOMINIO="prestamosqa.empresascloud.com" ;;
    *)   echo "ENTORNO debe ser dev o qa (producción todavía no está habilitada)" >&2; exit 2 ;;
esac
# Defensa: este script usa rsync --delete; jamás debe apuntar a una carpeta del ERP ni a la web pública.
case "$APP_DIR" in
    app.cloudbusines.com|demo.cloudbusines.com|appqa.cloudbusines.com|public_html|"")
        echo "❌ $APP_DIR pertenece al ERP: se detiene" >&2; exit 2 ;;
esac

SSH_CONTROL_PATH="${RUNNER_TEMP:-/tmp}/prestacloud-ssh-%C"
SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20
          -o ControlMaster=auto -o ControlPersist=120 -o "ControlPath=$SSH_CONTROL_PATH"
          -o ServerAliveInterval=15 -o ServerAliveCountMax=3)
if [ -n "${SSH_KEY_FILE:-}" ]; then SSH_OPTS+=(-i "${SSH_KEY_FILE/#~/$HOME}" -o IdentitiesOnly=yes); fi
DESTINO="$SSH_USER@$SSH_HOST"
trap 'ssh "${SSH_OPTS[@]}" -O exit "$DESTINO" >/dev/null 2>&1 || true' EXIT
# shellcheck disable=SC2029  # las variables se expanden aquí a propósito: el servidor recibe el comando ya armado
remoto() { ssh "${SSH_OPTS[@]}" "$DESTINO" "$@"; }
subir() { rsync -az -e "ssh ${SSH_OPTS[*]}" "$@"; }
# Con --delete: solo para la carpeta que es exclusivamente de PrestaCloud
sincronizar() { rsync -az --delete -e "ssh ${SSH_OPTS[*]}" "$@"; }

APP="prestacloud-app"
COMMIT=$(git log --oneline -1 2>/dev/null || echo "?")
RUN_ID="${GITHUB_RUN_ID:-manual-$(date +%Y%m%d%H%M%S)-$$}"
[[ "$RUN_ID" =~ ^[a-zA-Z0-9_-]+$ ]] || { echo "Identificador de ejecución inválido" >&2; exit 2; }
REMOTE_STAGE="pc-deploy/$ENTORNO/$RUN_ID"

echo "🚀 Deploy PrestaCloud [$ENTORNO] → $DOMINIO"
echo "   Commit: $COMMIT"

# ─── 0. Comprobaciones ─────────────────────────────────────
[ -f "$APP/vendor/autoload.php" ] || { echo "❌ Falta $APP/vendor/autoload.php: ejecuta composer install --no-dev antes del deploy" >&2; exit 1; }
[ -f "$APP/migrations/migration_001_esquema.sql" ] || { echo "❌ Faltan las migraciones" >&2; exit 1; }
remoto "test -f ~/$APP_DIR/.env" || { echo "❌ ~/$APP_DIR/.env no existe en el servidor: créalo (permisos 600) a partir de .env.example" >&2; exit 1; }
# La carpeta no puede ser una instalación del ERP (con --delete la vaciaría)
remoto "test ! -d ~/$APP_DIR/Conect && test ! -d ~/$APP_DIR/ajax && test ! -d ~/$APP_DIR/Controladores" \
    || { echo "❌ ~/$APP_DIR parece una instalación del ERP: se detiene sin tocarla" >&2; exit 1; }

# Lo que nunca se sube ni se borra en el servidor (rsync no borra lo excluido): deploy/protegidos.sh,
# probado por deploy/ci/probar-rsync.sh
# shellcheck source=protegidos.sh
source "$(dirname "$0")/protegidos.sh"

# Versión visible en la página de estado y en salud.php (no se versiona)
echo "$(git rev-parse --short HEAD 2>/dev/null || echo '?') · $(date -u +%Y-%m-%dT%H:%MZ) · $ENTORNO" > "$APP/VERSION"

# ─── 1. Backup + migraciones ───────────────────────────────
echo ""
echo "🗄️  [1/2] Backup y migraciones"
remoto "mkdir -p ~/$REMOTE_STAGE"
subir deploy/servidor.sh "$APP/migrations" "$DESTINO:$REMOTE_STAGE/"
remoto "test -f ~/$REMOTE_STAGE/servidor.sh && test -f ~/$REMOTE_STAGE/migrations/migration_001_esquema.sql"
remoto "bash ~/$REMOTE_STAGE/servidor.sh migrar $ENTORNO ~/$REMOTE_STAGE/migrations ~/$APP_DIR"

# ─── 2. Código ─────────────────────────────────────────────
echo ""
echo "⚙️  [2/2] Código → ~/$APP_DIR"
sincronizar "${PROTEGIDOS[@]}" "$APP/" "$DESTINO:$APP_DIR/"
subir "$APP/.htaccess" "$DESTINO:$APP_DIR/.htaccess.repo"
subir "$APP/public/.htaccess" "$DESTINO:$APP_DIR/public/.htaccess.repo"
remoto "bash ~/$REMOTE_STAGE/servidor.sh htaccess ~/$APP_DIR ~/$APP_DIR/.htaccess.repo \
    && bash ~/$REMOTE_STAGE/servidor.sh htaccess ~/$APP_DIR/public ~/$APP_DIR/public/.htaccess.repo \
    && mkdir -p ~/$APP_DIR/storage ~/$APP_DIR/sesiones \
    && chmod 755 ~/$APP_DIR/storage && chmod 700 ~/$APP_DIR/sesiones \
    && chmod 600 ~/$APP_DIR/.env"

# Carpetas de ejecuciones anteriores: se guardan las 5 últimas por entorno
remoto "ls -1dt ~/pc-deploy/$ENTORNO/*/ 2>/dev/null | tail -n +6 | xargs -r rm -rf" || true

echo ""
echo "✅ Deploy [$ENTORNO] terminado — $COMMIT"

#!/bin/bash
# ============================================================
# PrestaCloud — tareas que corren EN EL SERVIDOR durante el deploy.
# Lo copia y ejecuta deploy/deploy.sh; no se llama a mano.
#
#   servidor.sh migrar <entorno> <dir_migraciones> <dir_app>
#       Lee la base de datos del .env de <dir_app>, hace un backup comprimido en
#       ~/backups_prestacloud/ (se guardan los 15 últimos) y aplica las migraciones pendientes,
#       registrándolas por nombre en pc_migraciones. Si una falla, termina con error:
#       deploy.sh no copia el código nuevo.
#
#   servidor.sh htaccess <dir> <htaccess_nuevo>
#       Instala el .htaccess del repositorio conservando el bloque que cPanel agrega
#       para elegir la versión de PHP.
# ============================================================
set -euo pipefail

accion="${1:-}"; shift || true

# Lee una variable de un .env sin ejecutarlo (quita comillas). Vacío si no está.
leer_env() {
    local archivo="$1" clave="$2"
    grep -E "^[[:space:]]*${clave}[[:space:]]*=" "$archivo" | tail -1 \
        | sed -E "s/^[^=]*=[[:space:]]*//; s/[[:space:]]+#.*$//; s/^\"(.*)\"$/\1/; s/^'(.*)'$/\1/" || true
}

# ── htaccess ────────────────────────────────────────────────
if [ "$accion" = "htaccess" ]; then
    dir="$1"; nuevo="$2"
    actual="$dir/.htaccess"
    bloque=""
    if [ -f "$actual" ]; then
        bloque=$(sed -n '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/p' "$actual")
    fi
    [ -f "$actual" ] && cp "$actual" "$actual.anterior"
    { cat "$nuevo"; [ -n "$bloque" ] && printf '\n%s\n' "$bloque"; } > "$actual.tmp"
    mv "$actual.tmp" "$actual"
    rm -f "$nuevo"
    echo "   .htaccess de $dir actualizado${bloque:+ (se conservó el handler de PHP de cPanel)}"
    exit 0
fi

# ── migrar ──────────────────────────────────────────────────
if [ "$accion" != "migrar" ]; then
    echo "Uso: servidor.sh migrar|htaccess ..." >&2
    exit 2
fi

entorno="$1"; dir_mig="$2"; dir_app="$3"
[ -d "$dir_mig" ] && [ -f "$dir_mig/migration_001_esquema.sql" ] \
    || { echo "Paquete de migraciones incompleto: $dir_mig" >&2; exit 1; }
env_file="$dir_app/.env"
[ -f "$env_file" ] || { echo "❌ $dir_app no tiene .env: créalo antes de desplegar (DEPLOY.md)" >&2; exit 1; }

BACKUPS="$HOME/backups_prestacloud"
mkdir -p "$BACKUPS"
chmod 700 "$BACKUPS"

host=$(leer_env "$env_file" DB_HOST); port=$(leer_env "$env_file" DB_PORT)
db=$(leer_env "$env_file" DB_NAME);   user=$(leer_env "$env_file" DB_USER)
pass=$(leer_env "$env_file" DB_PASS)
host=${host:-localhost}; port=${port:-3306}
[[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo "❌ DB_NAME no válido en $dir_app" >&2; exit 1; }

# Credenciales en un archivo temporal (no en la línea de comandos)
cnf=$(mktemp); chmod 600 "$cnf"
trap 'rm -f "$cnf"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' "$host" "$port" "$user" "$pass" > "$cnf"
MYSQL=(mysql --defaults-extra-file="$cnf" --default-character-set=utf8mb4 "$db")

echo "🗄️  Base de datos $db ($dir_app)"

# 1. Backup. mysqldump 8 contra un servidor 5.7 necesita --column-statistics=0; el 5.7 no conoce esa opción.
extra=()
if mysqldump --help 2>/dev/null | grep -q 'column-statistics'; then extra+=(--column-statistics=0); fi
archivo="$BACKUPS/${entorno}_${db}_$(date +%Y%m%d_%H%M%S).sql.gz"
mysqldump --defaults-extra-file="$cnf" --single-transaction --routines --triggers \
    --no-tablespaces ${extra[@]+"${extra[@]}"} --default-character-set=utf8mb4 "$db" | gzip > "$archivo"
chmod 600 "$archivo"
echo "   💾 Backup: $archivo ($(du -h "$archivo" | cut -f1))"
# shellcheck disable=SC2012  # ls -t ordena por fecha; los nombres los genera este mismo script
ls -1t "$BACKUPS/${entorno}_${db}_"*.sql.gz 2>/dev/null | tail -n +16 | xargs -r rm -f

# 2. Registro de migraciones
"${MYSQL[@]}" -e "CREATE TABLE IF NOT EXISTS pc_migraciones (
    nombre VARCHAR(120) PRIMARY KEY,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nota VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"

# Todas las migraciones numeradas (migration_NNN_*.sql, tres o más cifras) en orden NUMÉRICO:
# la 100 va después de la 099. Lo ya aplicado se salta por su nombre. Solo nombres con letras, cifras, «_» y «-»
# porque el nombre se escribe en SQL.
mapfile -t pendientes < <(find "$dir_mig" -maxdepth 1 -type f -name 'migration_*.sql' -printf '%f\n' \
    | grep -E '^migration_[0-9]{3,}_[A-Za-z0-9_-]+\.sql$' | sort -t_ -k2,2n)
mapfile -t raros < <(find "$dir_mig" -maxdepth 1 -type f -name 'migration_*.sql' -printf '%f\n' \
    | { grep -vE '^migration_[0-9]{3,}_[A-Za-z0-9_-]+\.sql$' || true; })
if [ "${#raros[@]}" -gt 0 ]; then
    echo "   ❌ Nombre de migración no válido (migration_NNN_nombre.sql, solo letras, cifras, «_» y «-»): ${raros[*]}" >&2; exit 1
fi

aplicadas=0
for nombre in "${pendientes[@]}"; do
    if [ "$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM pc_migraciones WHERE nombre='$nombre'")" != "0" ]; then
        continue
    fi
    "${MYSQL[@]}" < "$dir_mig/$nombre"
    "${MYSQL[@]}" -e "INSERT IGNORE INTO pc_migraciones (nombre, nota) VALUES ('$nombre', 'deploy $entorno')"
    echo "   ✓ $nombre"
    aplicadas=$((aplicadas + 1))
done
echo "   Migraciones: $aplicadas aplicada(s), $(( ${#pendientes[@]} - aplicadas )) ya estaban."

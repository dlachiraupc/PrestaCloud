#!/bin/bash
# Aplica las migraciones sobre un MySQL 5.7 desechable con el MISMO script que usa el deploy
# (deploy/servidor.sh migrar), dos veces: la segunda no debe cambiar nada (idempotencia).
#
# Variables: DB_HOST (127.0.0.1), MYSQL_ROOT_PASSWORD, DB_NAME (prestacloud_ci), DB_USER (prestacloud_ci), DB_PASS.
# Local, con el MySQL de docker compose:
#   docker run --rm --network prestacloud_default -v "$PWD:/repo:ro" -e DB_HOST=db -e MYSQL_ROOT_PASSWORD=root \
#       mysql:5.7.44 bash /repo/deploy/ci/migrar-ci.sh
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-prestacloud_ci}"
DB_USER="${DB_USER:-prestacloud_ci}"
DB_PASS="${DB_PASS:-ci-pass}"
: "${MYSQL_ROOT_PASSWORD:?Falta MYSQL_ROOT_PASSWORD}"
RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"

root=(mysql -h"$DB_HOST" -uroot -p"$MYSQL_ROOT_PASSWORD")
"${root[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
                 GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"

# «Servidor» simulado: carpeta con .env y HOME propio (los backups no ensucian el equipo)
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/app" "$tmp/home"
printf 'DB_HOST=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" > "$tmp/app/.env"

total=$(find "$RAIZ/prestacloud-app/migrations" -maxdepth 1 -name 'migration_*.sql' | wc -l)
for vuelta in 1 2; do
    echo "── Vuelta $vuelta ──"
    HOME="$tmp/home" bash "$RAIZ/deploy/servidor.sh" migrar ci "$RAIZ/prestacloud-app/migrations" "$tmp/app"
done
registradas=$("${root[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.pc_migraciones")
[ "$registradas" = "$total" ] || { echo "❌ pc_migraciones tiene $registradas y hay $total migraciones" >&2; exit 1; }
tablas=$("${root[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME'")
echo "✅ Migraciones idempotentes: $registradas registrada(s), $tablas tablas en $DB_NAME"

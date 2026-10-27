#!/bin/bash
# Prueba, sin red ni servidor, que `rsync --delete` con la lista de deploy/protegidos.sh:
#   · conserva .env, storage/, sesiones/, los .htaccess de cPanel y los archivos de cPanel
#   · borra lo que ya no existe en el repositorio
#   · no sube tests/, migrations/ ni database/
# Necesita rsync. Local:  docker run --rm -v "$PWD:/repo:ro" alpine sh -c 'apk add -q bash rsync && bash /repo/deploy/ci/probar-rsync.sh'
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../protegidos.sh
source "$RAIZ/deploy/protegidos.sh"

tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
origen="$tmp/origen"; destino="$tmp/servidor"
mkdir -p "$origen"/{src,public,tests,migrations,database} "$destino"/{src,public,storage,sesiones,.well-known,cgi-bin}

# Lo que sube el deploy (el repositorio)
echo 'nuevo'  > "$origen/src/nuevo.php"
echo 'repo'   > "$origen/.htaccess";        echo 'repo' > "$origen/public/.htaccess"
echo 'NO'     > "$origen/.env"             # aunque alguien lo dejara en el origen, no debe pisar el del servidor
echo 'NO'     > "$origen/tests/t.php";     echo 'NO' > "$origen/migrations/m.sql"; echo 'NO' > "$origen/database/seed.sql"

# Lo que ya hay en el servidor
echo 'DB_PASS=secreto' > "$destino/.env"
echo 'dato'    > "$destino/storage/archivo"; echo 'sesion' > "$destino/sesiones/s"
bloque=$'# php -- BEGIN cPanel-generated handler\nAddHandler x\n# php -- END cPanel-generated handler'
printf '%s\n' "$bloque" > "$destino/.htaccess"; printf '%s\n' "$bloque" > "$destino/public/.htaccess"
echo 'c' > "$destino/.well-known/c"; echo 'e' > "$destino/error_log"; echo 'u' > "$destino/.user.ini"; echo 'p' > "$destino/php.ini"
echo 'viejo' > "$destino/src/obsoleto.php"; echo 'x' > "$destino/suelto.txt"

rsync -a --delete "${PROTEGIDOS[@]}" "$origen/" "$destino/"

fallos=0
debe_existir() { [ -e "$destino/$1" ] || { echo "❌ se perdió $1" >&2; fallos=1; }; }
no_debe_existir() { [ ! -e "$destino/$1" ] || { echo "❌ no debería existir $1" >&2; fallos=1; }; }
debe_existir src/nuevo.php
for f in .env storage/archivo sesiones/s .well-known/c error_log .user.ini php.ini; do debe_existir "$f"; done
grep -q 'DB_PASS=secreto' "$destino/.env" || { echo "❌ el .env del servidor cambió" >&2; fallos=1; }
grep -q 'cPanel-generated handler' "$destino/.htaccess" || { echo "❌ .htaccess raíz perdió el bloque de PHP" >&2; fallos=1; }
grep -q 'cPanel-generated handler' "$destino/public/.htaccess" || { echo "❌ public/.htaccess perdió el bloque de PHP" >&2; fallos=1; }
no_debe_existir src/obsoleto.php
no_debe_existir suelto.txt
no_debe_existir tests; no_debe_existir migrations; no_debe_existir database
[ "$fallos" = 0 ] && echo "✅ rsync --delete respeta .env, storage, sesiones, .htaccess y archivos de cPanel; borra solo lo obsoleto"
exit "$fallos"

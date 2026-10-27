#!/bin/bash
# Prueba de publicación anónima: no crea cuentas ni datos y no envía correo.
#   bash deploy/verificar-publicacion.sh dev|qa|local
# «local» comprueba http://localhost:9091 (docker compose up). BASE_URL reemplaza la dirección.
set -euo pipefail
entorno="${1:?Indica dev, qa o local}"
case "$entorno" in
    dev)   base="https://prestamosdev.empresascloud.com" ;;
    qa)    base="https://prestamosqa.empresascloud.com" ;;
    local) base="http://localhost:9091" ;;
    *)     echo 'Entorno inválido' >&2; exit 2 ;;
esac
base="${BASE_URL:-$base}"
fallo=0
NL=$'\n'

# Deja el código HTTP en $CODIGO y el cuerpo en $CUERPO (sin archivos temporales: igual en Linux y en Windows).
pedir() {
    local r
    r=$(curl --silent --show-error --max-time 25 --write-out "${NL}%{http_code}" "$1" 2>/dev/null) || r="${NL}000"
    CODIGO="${r##*"$NL"}"
    CUERPO="${r%"$NL"*}"
}

# 1. La página de estado responde
pedir "$base/"
echo "$base/ → $CODIGO (esperado 200)"
if [ "$CODIGO" != "200" ] || [[ "$CUERPO" != *PrestaCloud* ]]; then
    echo "   la portada no es la de PrestaCloud" >&2; fallo=1
fi

# 2. salud.php: base de datos y núcleo de cálculo funcionando en ese servidor
for _ in 1 2 3; do
    pedir "$base/salud.php"
    [ "$CODIGO" = "200" ] && break
    sleep 2
done
echo "$base/salud.php → $CODIGO (esperado 200) · $(printf '%s' "$CUERPO" | tr -d '\n' | cut -c1-160)"
if [ "$CODIGO" != "200" ] || [[ "$CUERPO" != *'"ok":true'* ]] || [[ "$CUERPO" != *'"nucleo":true'* ]]; then
    echo "   salud.php no está sano" >&2; fallo=1
fi
if [[ "$CUERPO" != *'"php":"8.2"'* ]]; then
    echo "   El servidor no usa PHP 8.2 (cPanel → Select PHP Version del subdominio)" >&2; fallo=1
fi

# 3. Nada interno se puede descargar: ni código, ni .env, ni migraciones, ni dependencias.
#    Se exige que NO sea 200 y que el cuerpo no contenga rastros de esos archivos.
for ruta in /.env /composer.json /composer.lock /src/Core/Reglas.php /vendor/autoload.php /migrations/migration_001_esquema.sql /tests/Ayudas.php /VERSION; do
    pedir "$base$ruta"
    if [ "$CODIGO" = "200" ] || [[ "$CUERPO" =~ DB_PASS|namespace\ PrestaCloud|CREATE\ TABLE|ClassLoader ]]; then
        echo "$base$ruta → $CODIGO: SE PUEDE LEER algo interno" >&2; fallo=1
    else
        echo "$base$ruta → $CODIGO (no se sirve)"
    fi
done
exit "$fallo"

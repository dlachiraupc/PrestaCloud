#!/bin/bash
# Lista de lo que el deploy NUNCA sube ni borra en el servidor. La usa deploy.sh con `rsync --delete`
# (rsync no borra lo excluido) y la prueba deploy/ci/probar-rsync.sh. Si cambia, la prueba debe seguir en verde.
#
#  .env                      credenciales de ese entorno (las crea Daniel a mano)
#  storage/ y sesiones/      datos generados por la aplicación
#  .htaccess (raíz y public) los reemplaza servidor.sh conservando el bloque de PHP de cPanel
#  .well-known, cgi-bin, error_log, .user.ini, php.ini    archivos de cPanel (AutoSSL, registros, PHP)
#  tests/, migrations/, database/, phpunit.xml            no se publican en la raíz web; las migraciones viajan aparte
# shellcheck disable=SC2034  # PROTEGIDOS se usa desde quien hace `source` de este archivo
PROTEGIDOS=(
    --exclude='.git' --exclude='.env' --exclude='/storage/' --exclude='/sesiones/'
    --exclude='/.htaccess' --exclude='/.htaccess.repo' --exclude='/.htaccess.anterior'
    --exclude='/public/.htaccess' --exclude='/public/.htaccess.repo' --exclude='/public/.htaccess.anterior'
    --exclude='.well-known/' --exclude='cgi-bin/' --exclude='error_log' --exclude='.user.ini' --exclude='php.ini'
    --exclude='/tests/' --exclude='/.phpunit.cache/' --exclude='/phpunit.xml'
    --exclude='/migrations/' --exclude='/database/'
)

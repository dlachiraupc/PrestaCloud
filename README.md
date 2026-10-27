# PrestaCloud

Gestión de préstamos y cobranza para prestamistas: clientes, préstamos, cuotas, cobranza del día, caja y calificación de clientes.
Servicio de **EmpresasCloud**, en el mismo hosting (cPanel, PHP 8.2, MySQL 5.7) que el ERP, con repositorio, base de datos y despliegue propios.

Estado: **Fase 0**. Motor de cálculo, esquema de base de datos y despliegue a dev/QA; todavía no hay pantallas (la raíz web solo muestra una
página de estado). Ver `DOCUMENTOS/Plan_Fases_y_Validacion.md`.

**Las reglas de negocio son de cada cuenta** (cada prestamista define las suyas: modalidades, tasas, cuotas, puntualidad, calificación y
tipos de caja). El motor no tiene reglas propias: siempre recibe las de la cuenta. Ver `DOCUMENTOS/REGLAS_DE_NEGOCIO.md`.

## Estructura

```
prestacloud-app/
├── src/Core/        # Motor de cálculo en PHP puro (sin base de datos ni HTTP): reglas de la cuenta, plan de pago, asignación
│                    #   de pagos, puntualidad, estado, calificación, cobranza, caja. Importes en céntimos enteros.
├── src/Infra/       # Entorno (.env), conexión PDO y ReglasRepositorio (reglas por cuenta en config_*)
├── public/          # Raíz web: index.php (único punto de entrada) y salud.php
├── migrations/      # migration_NNN_*.sql, idempotentes, MySQL 5.7, con tenant_id en toda tabla de negocio
├── tests/Unit/      # Casos T1–T24 del documento de validación del Excel v13 y reglas por cuenta
├── tests/Paridad/   # El motor contra los valores guardados del libro PRESTAMOS v13
├── tests/Integracion/ # Contra MySQL 5.7 real (PC_TEST_DB=1)
└── tests/Fixtures/  # ReglasV13 (las reglas de Daniel, como dato de prueba) y v13_golden.json (solo códigos y cifras, sin nombres)
deploy/              # deploy.sh, servidor.sh, verificar-publicacion.sh y pruebas del propio despliegue (ci/)
docker/php/          # PHP 8.2 + Apache, igual que el hosting
tools/               # Herramientas de desarrollo (no se despliegan)
DOCUMENTOS/          # Propuesta, mockup, plan por fases, reglas de negocio
```

## Pruebas

Solo hace falta Docker. PHP y Composer no se instalan en el equipo.

```bash
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app vendor/bin/phpunit          # unitarias y paridad (las de base de datos se omiten)
```

Con la base de datos (MySQL 5.7), igual que el CI:

```bash
docker compose up -d db
MSYS_NO_PATHCONV=1 docker run --rm --network prestacloud_default -v "$PWD:/repo:ro" -e DB_HOST=db -e MYSQL_ROOT_PASSWORD=root \
    mysql:5.7.44 bash /repo/deploy/ci/migrar-ci.sh
docker compose run --rm --no-deps -e PC_TEST_DB=1 -e DB_HOST=db -e DB_NAME=prestacloud_ci -e DB_USER=prestacloud_ci -e DB_PASS=ci-pass \
    app vendor/bin/phpunit
```

`vendor/` vive en un volumen de Docker (no se sincroniza con OneDrive). La app local queda en http://localhost:9091 con `docker compose up -d`.

## Despliegue

Ramas `develop` → dev y `staging` → QA, con GitHub Actions por SSH al mismo hosting que el ERP. Ver `DEPLOY.md`.

## Dataset dorado (paridad con el Excel)

`tests/Fixtures/v13_golden.json` se genera a partir de los **valores guardados** del libro, sin nombres:

```bash
cd tools && npm install
node tools/generar_golden_v13.mjs <ruta/PRESTAMOS_v13.xlsx>
```

El libro de Excel nunca se versiona (`*.xlsx` está en `.gitignore`). Los datos de clientes reales tampoco: Ley N.º 29733.

## Reglas

Ver `DOCUMENTOS/REGLAS_DE_NEGOCIO.md`. Todo lo que el motor hace distinto del Excel está en la sección de desviaciones.

-- ============================================================
-- PrestaCloud · migration_001_esquema
-- Esquema inicial. MySQL 5.7 (igual que el hosting). Idempotente: se puede ejecutar más de una vez.
--
-- Reglas de este esquema:
--  * Toda tabla de negocio lleva tenant_id (una cuenta = un prestamista).
--  * Cada tabla de negocio tiene UNIQUE (tenant_id, id) y las tablas hijas la referencian con una clave foránea
--    COMPUESTA (tenant_id, X_id): la base rechaza un préstamo de una cuenta con un cliente de otra, aunque el
--    código tuviera un error.
--  * Las reglas de negocio (modalidades, tramos, calificación…) son DATOS de cada cuenta: tablas config_*.
--  * Importes en céntimos (BIGINT). Nunca se borra: se anula.
--  * Sin CHECK (MySQL 5.7 los ignora): las validaciones viven en el núcleo (src/Core).
-- ============================================================
SET NAMES utf8mb4;

-- ───────────── Planes y cuentas ─────────────
CREATE TABLE IF NOT EXISTS planes (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo                VARCHAR(20)  NOT NULL,
    nombre                VARCHAR(40)  NOT NULL,
    precio_mensual        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'céntimos; referencial hasta validar en el piloto',
    max_clientes_activos  INT UNSIGNED NULL COMMENT 'NULL = ilimitados',
    max_usuarios          INT UNSIGNED NOT NULL DEFAULT 1,
    funciones             TEXT NULL COMMENT 'JSON con las funciones habilitadas',
    PRIMARY KEY (id),
    UNIQUE KEY uk_planes_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO planes (codigo, nombre, precio_mensual, max_clientes_activos, max_usuarios) VALUES
    ('gratis',  'Gratis',  0,    15,   1),
    ('pro',     'Pro',     3500, NULL, 1),
    ('negocio', 'Negocio', 7900, NULL, 6)
ON DUPLICATE KEY UPDATE codigo = codigo;

CREATE TABLE IF NOT EXISTS tenants (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_negocio  VARCHAR(120) NOT NULL,
    slug            VARCHAR(40)  NOT NULL,
    plan_id         INT UNSIGNED NOT NULL,
    estado          ENUM('prueba','activo','solo_lectura','suspendido') NOT NULL DEFAULT 'prueba',
    prueba_hasta    DATE NULL,
    vence_el        DATE NULL,
    zona_horaria    VARCHAR(40)  NOT NULL DEFAULT 'America/Lima',
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenants_slug (slug),
    KEY ix_tenants_plan (plan_id),
    CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES planes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          INT UNSIGNED NOT NULL,
    nombre             VARCHAR(120) NOT NULL,
    correo             VARCHAR(150) NOT NULL,
    celular            VARCHAR(20)  NULL,
    rol                ENUM('dueno','cobrador','inversionista') NOT NULL DEFAULT 'dueno',
    password_hash      VARCHAR(255) NOT NULL,
    activo             TINYINT(1)   NOT NULL DEFAULT 1,
    intentos_fallidos  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta    DATETIME NULL,
    creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_tenant_id (tenant_id, id),
    UNIQUE KEY uk_usuarios_correo (correo),
    CONSTRAINT fk_usuarios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contadores atómicos por cuenta (códigos de cliente y préstamo, n.º de constancia)
CREATE TABLE IF NOT EXISTS contadores (
    tenant_id  INT UNSIGNED NOT NULL,
    nombre     VARCHAR(30)  NOT NULL,
    valor      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, nombre),
    CONSTRAINT fk_contadores_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── Reglas de negocio: DATOS de cada cuenta ─────────────
CREATE TABLE IF NOT EXISTS config_modalidades (
    tenant_id  INT UNSIGNED NOT NULL,
    codigo     VARCHAR(30)  NOT NULL,
    cada       SMALLINT UNSIGNED NOT NULL,
    unidad     ENUM('DIAS','MESES') NOT NULL DEFAULT 'DIAS',
    dia_fijo   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 ninguno · 1 domingo … 7 sábado (WEEKDAY de Excel)',
    orden      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, codigo),
    CONSTRAINT fk_cfgmod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_tramos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  INT UNSIGNED NOT NULL,
    modalidad  VARCHAR(30)  NOT NULL,
    desde      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'céntimos',
    tasa_bp    INT UNSIGNED NOT NULL COMMENT 'puntos básicos: 2000 = 20 %',
    n_cuotas   SMALLINT UNSIGNED NOT NULL,
    redondeo   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'soles: 0 exacto, 1 enteros, 5 múltiplos de 5',
    PRIMARY KEY (id),
    UNIQUE KEY uk_cfgtramos (tenant_id, modalidad, desde),
    CONSTRAINT fk_cfgtramos_mod FOREIGN KEY (tenant_id, modalidad) REFERENCES config_modalidades (tenant_id, codigo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_tipos_caja (
    tenant_id  INT UNSIGNED NOT NULL,
    tipo       VARCHAR(30)  NOT NULL,
    signo      TINYINT      NOT NULL COMMENT '1 suma · -1 resta · 0 solo se anota',
    PRIMARY KEY (tenant_id, tipo),
    CONSTRAINT fk_cfgcaja_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_calificacion (
    tenant_id     INT UNSIGNED NOT NULL,
    calificacion  ENUM('PUNTUAL','DESPUES_DE_LA_HORA','DESPUES_DE_LA_FECHA','NO_RENOVAR') NOT NULL,
    aumento       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'céntimos sugeridos de aumento al renovar',
    renueva       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, calificacion),
    CONSTRAINT fk_cfgcal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_puntualidad (
    tenant_id       INT UNSIGNED NOT NULL,
    hora_maxima     CHAR(5)  NOT NULL DEFAULT '17:00',
    tolerancia_dias TINYINT UNSIGNED NOT NULL DEFAULT 0,
    n_hora          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    n_fecha         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    n_no_renovar    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_cfgpun_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── Clientes, préstamos y cuotas ─────────────
CREATE TABLE IF NOT EXISTS clientes (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED NOT NULL,
    codigo              VARCHAR(12)  NOT NULL,
    nombre              VARCHAR(150) NOT NULL,
    diminutivo          VARCHAR(60)  NOT NULL,
    dni                 VARCHAR(15)  NULL,
    telefono            VARCHAR(20)  NULL,
    referencia          VARCHAR(255) NULL,
    notas               TEXT NULL,
    calificacion_manual ENUM('PUNTUAL','DESPUES_DE_LA_HORA','DESPUES_DE_LA_FECHA','NO_RENOVAR') NULL COMMENT 'si existe, manda sobre la automática',
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clientes_tenant_id (tenant_id, id),
    UNIQUE KEY uk_clientes_codigo (tenant_id, codigo),
    UNIQUE KEY uk_clientes_diminutivo (tenant_id, diminutivo),
    KEY ix_clientes_telefono (tenant_id, telefono),
    KEY ix_clientes_dni (tenant_id, dni),
    CONSTRAINT fk_clientes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prestamos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      INT UNSIGNED NOT NULL,
    codigo         VARCHAR(12)  NOT NULL,
    cliente_id     INT UNSIGNED NOT NULL,
    modalidad      VARCHAR(30)  NOT NULL,
    fecha_entrega  DATE         NOT NULL,
    monto          BIGINT UNSIGNED NOT NULL,
    -- Plan calculado al entregar y guardado tal cual: cambiar un tramo después no recalcula préstamos entregados
    tasa_bp        INT UNSIGNED NOT NULL,
    n_cuotas       SMALLINT UNSIGNED NOT NULL,
    total          BIGINT UNSIGNED NOT NULL,
    cuota_primera  BIGINT UNSIGNED NOT NULL,
    cuota_normal   BIGINT UNSIGNED NOT NULL,
    cobrador_id    INT UNSIGNED NULL,
    anulado        TINYINT(1)   NOT NULL DEFAULT 0,
    anulado_en     DATETIME NULL,
    anulado_por    INT UNSIGNED NULL,
    creado_por     INT UNSIGNED NULL,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_prestamos_tenant_id (tenant_id, id),
    UNIQUE KEY uk_prestamos_codigo (tenant_id, codigo),
    KEY ix_prestamos_cliente (tenant_id, cliente_id),
    KEY ix_prestamos_fecha (tenant_id, fecha_entrega),
    CONSTRAINT fk_prestamos_cliente  FOREIGN KEY (tenant_id, cliente_id)  REFERENCES clientes (tenant_id, id),
    CONSTRAINT fk_prestamos_cobrador FOREIGN KEY (tenant_id, cobrador_id) REFERENCES usuarios (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cuotas (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    INT UNSIGNED NOT NULL,
    prestamo_id  INT UNSIGNED NOT NULL,
    numero       SMALLINT UNSIGNED NOT NULL,
    vence_el     DATE         NOT NULL,
    monto        BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cuotas_tenant_id (tenant_id, id),
    UNIQUE KEY uk_cuotas_numero (tenant_id, prestamo_id, numero),
    KEY ix_cuotas_vence (tenant_id, vence_el),
    CONSTRAINT fk_cuotas_prestamo FOREIGN KEY (tenant_id, prestamo_id) REFERENCES prestamos (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── Pagos y caja ─────────────
CREATE TABLE IF NOT EXISTS pagos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED NOT NULL,
    prestamo_id         INT UNSIGNED NOT NULL,
    fecha               DATE         NOT NULL,
    hora                TIME NULL COMMENT 'NULL en pagos migrados del Excel (no se conocía)',
    monto               BIGINT UNSIGNED NOT NULL,
    medio               VARCHAR(20)  NOT NULL DEFAULT 'Efectivo',
    despues_de_hora     TINYINT(1) NULL COMMENT 'marca manual (columna E del Excel); si existe, manda sobre la hora',
    observacion         VARCHAR(255) NULL,
    constancia_n        INT UNSIGNED NOT NULL,
    clave_idempotencia  VARCHAR(64)  NULL COMMENT 'evita duplicar un pago si el celular reintenta por mala señal',
    -- Caché de lo derivado (el núcleo es la fuente de verdad y lo recalcula en la misma transacción)
    cuota_n             SMALLINT UNSIGNED NULL,
    puntualidad         ENUM('PUNTUAL','ADELANTADO','DESPUES_DE_LA_HORA','DESPUES_DE_LA_FECHA') NULL,
    dias_tarde          SMALLINT UNSIGNED NULL,
    registrado_por      INT UNSIGNED NULL,
    anulado             TINYINT(1)   NOT NULL DEFAULT 0,
    anulado_en          DATETIME NULL,
    anulado_por         INT UNSIGNED NULL,
    motivo_anulacion    VARCHAR(255) NULL,
    creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pagos_tenant_id (tenant_id, id),
    UNIQUE KEY uk_pagos_constancia (tenant_id, constancia_n),
    UNIQUE KEY uk_pagos_idempotencia (tenant_id, clave_idempotencia),
    KEY ix_pagos_fecha (tenant_id, fecha),
    KEY ix_pagos_prestamo (tenant_id, prestamo_id, fecha),
    CONSTRAINT fk_pagos_prestamo FOREIGN KEY (tenant_id, prestamo_id) REFERENCES prestamos (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reparto derivado de cada pago entre cuotas (FIFO). Nadie lo edita; se recalcula al registrar o anular un pago.
CREATE TABLE IF NOT EXISTS pago_cuotas (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    pago_id     INT UNSIGNED NOT NULL,
    cuota_id    INT UNSIGNED NOT NULL,
    monto       BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pagocuotas (tenant_id, pago_id, cuota_id),
    KEY ix_pagocuotas_cuota (tenant_id, cuota_id),
    CONSTRAINT fk_pagocuotas_pago  FOREIGN KEY (tenant_id, pago_id)  REFERENCES pagos (tenant_id, id),
    CONSTRAINT fk_pagocuotas_cuota FOREIGN KEY (tenant_id, cuota_id) REFERENCES cuotas (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo movimientos que NO son préstamo ni cuota (aportes, retiros, gastos…). La caja se calcula.
CREATE TABLE IF NOT EXISTS caja_movimientos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    fecha       DATE         NOT NULL,
    tipo        VARCHAR(30)  NOT NULL COMMENT 'el signo sale de config_tipos_caja de esta cuenta',
    monto       BIGINT UNSIGNED NOT NULL,
    detalle     VARCHAR(255) NULL,
    usuario_id  INT UNSIGNED NULL,
    anulado     TINYINT(1)   NOT NULL DEFAULT 0,
    anulado_en  DATETIME NULL,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_caja_tenant_id (tenant_id, id),
    KEY ix_caja_fecha (tenant_id, fecha),
    CONSTRAINT fk_caja_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arqueos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    fecha       DATE         NOT NULL,
    esperado    BIGINT       NOT NULL COMMENT 'caja según el sistema, céntimos (puede ser negativa)',
    contado     BIGINT       NOT NULL COMMENT 'efectivo contado',
    diferencia  BIGINT       NOT NULL,
    nota        VARCHAR(255) NULL,
    usuario_id  INT UNSIGNED NULL,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_arqueos_fecha (tenant_id, fecha),
    CONSTRAINT fk_arqueos_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── Auditoría ─────────────
CREATE TABLE IF NOT EXISTS auditoria (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NULL,
    entidad     VARCHAR(30)  NOT NULL,
    entidad_id  VARCHAR(30)  NOT NULL,
    accion      VARCHAR(20)  NOT NULL COMMENT 'crear · editar · anular',
    antes       TEXT NULL,
    despues     TEXT NULL,
    ip          VARCHAR(45)  NULL,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_auditoria_entidad (tenant_id, entidad, entidad_id),
    KEY ix_auditoria_fecha (tenant_id, creado_en),
    CONSTRAINT fk_auditoria_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

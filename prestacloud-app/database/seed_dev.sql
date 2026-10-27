-- ============================================================
-- Datos de DESARROLLO local (no se despliega: está fuera de migrations/ y el deploy lo excluye).
-- Dos cuentas ficticias con reglas DISTINTAS para ver que cada prestamista trabaja con las suyas.
-- Las tasas son solo ejemplos para probar; no son una recomendación.
-- Uso:  docker compose exec -T db mysql -uprestacloud -pprestacloud prestacloud < prestacloud-app/database/seed_dev.sql
-- Es repetible: borra y vuelve a crear las dos cuentas demo.
-- ============================================================
SET NAMES utf8mb4;

DELETE FROM prestamos WHERE tenant_id IN (SELECT id FROM tenants WHERE slug IN ('demo-a', 'demo-b'));
DELETE FROM clientes  WHERE tenant_id IN (SELECT id FROM tenants WHERE slug IN ('demo-a', 'demo-b'));
DELETE FROM contadores WHERE tenant_id IN (SELECT id FROM tenants WHERE slug IN ('demo-a', 'demo-b'));
DELETE FROM tenants WHERE slug IN ('demo-a', 'demo-b');

INSERT INTO tenants (nombre_negocio, slug, plan_id, estado, prueba_hasta)
SELECT 'Demo A · cobra cada semana', 'demo-a', id, 'prueba', DATE_ADD(CURDATE(), INTERVAL 14 DAY) FROM planes WHERE codigo = 'pro';
INSERT INTO tenants (nombre_negocio, slug, plan_id, estado, prueba_hasta)
SELECT 'Demo B · cobra a diario y al mes', 'demo-b', id, 'prueba', DATE_ADD(CURDATE(), INTERVAL 14 DAY) FROM planes WHERE codigo = 'pro';

SET @a = (SELECT id FROM tenants WHERE slug = 'demo-a');
SET @b = (SELECT id FROM tenants WHERE slug = 'demo-b');

-- Cuenta A: semanal (sin día fijo), 10 % en 4 cuotas
INSERT INTO config_modalidades (tenant_id, codigo, cada, unidad, dia_fijo, orden) VALUES (@a, 'SEMANAL', 7, 'DIAS', 0, 0);
INSERT INTO config_tramos (tenant_id, modalidad, desde, tasa_bp, n_cuotas, redondeo) VALUES (@a, 'SEMANAL', 0, 1000, 4, 0);

-- Cuenta B: diaria 30 % en 6 cuotas; mensual por calendario 8 % en 3 cuotas (y 7 % en 6 cuotas desde S/ 1,000)
INSERT INTO config_modalidades (tenant_id, codigo, cada, unidad, dia_fijo, orden) VALUES
    (@b, 'DIARIA', 1, 'DIAS', 0, 0), (@b, 'MENSUAL', 1, 'MESES', 0, 1);
INSERT INTO config_tramos (tenant_id, modalidad, desde, tasa_bp, n_cuotas, redondeo) VALUES
    (@b, 'DIARIA', 0, 3000, 6, 0), (@b, 'MENSUAL', 0, 800, 3, 0), (@b, 'MENSUAL', 100000, 700, 6, 1);

-- Lo común a las dos cuentas (cada una podrá cambiarlo)
INSERT INTO config_tipos_caja (tenant_id, tipo, signo)
SELECT t.id, x.tipo, x.signo FROM tenants t JOIN (
    SELECT 'SALDO INICIAL' tipo, 1 signo UNION ALL SELECT 'APORTE CAPITAL', 1 UNION ALL SELECT 'OTRO INGRESO', 1 UNION ALL
    SELECT 'RETIRO', -1 UNION ALL SELECT 'GASTO', -1 UNION ALL SELECT 'PAGO A INVERSIONISTA', -1 UNION ALL SELECT 'POR CONFIRMAR', 0
) x WHERE t.slug IN ('demo-a', 'demo-b');
INSERT INTO config_calificacion (tenant_id, calificacion, aumento, renueva)
SELECT t.id, x.c, x.aumento, x.renueva FROM tenants t JOIN (
    SELECT 'PUNTUAL' c, 10000 aumento, 1 renueva UNION ALL SELECT 'DESPUES_DE_LA_HORA', 0, 1 UNION ALL
    SELECT 'DESPUES_DE_LA_FECHA', 0, 0 UNION ALL SELECT 'NO_RENOVAR', 0, 0
) x WHERE t.slug IN ('demo-a', 'demo-b');
INSERT INTO config_puntualidad (tenant_id, hora_maxima, tolerancia_dias, n_hora, n_fecha, n_no_renovar)
VALUES (@a, '17:00', 0, 1, 1, 3), (@b, '18:00', 1, 1, 2, 4);

INSERT INTO clientes (tenant_id, codigo, nombre, diminutivo, telefono) VALUES
    (@a, 'C001', 'Cliente Ejemplo Uno', 'UNO', '900 000 001'),
    (@b, 'C001', 'Cliente Ejemplo Uno', 'UNO', '900 000 002');

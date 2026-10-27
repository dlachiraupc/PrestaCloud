# Reglas de negocio de PrestaCloud

**Versión 0.1 · 27 de octubre de 2026 · para revisión de Daniel**

Son las reglas exactas que implementa el motor de cálculo (`prestacloud-app/src/Core`). Nacen del libro PRESTAMOS v13 y se
verifican contra él celda por celda (`tests/Paridad`). Cada regla indica su prueba. Importes en **céntimos enteros**; fechas sin hora;
la hora local es **America/Lima**.

## 0. Las reglas son de cada cuenta

Nada de lo que sigue está fijado en el código. **Cada prestamista (cada cuenta) define sus propias reglas**, y el motor siempre
calcula con las de la cuenta de que se trate:

| Qué configura cada cuenta | Dónde se guarda |
|---|---|
| Modalidades: cada N días (diaria, cada 3 días, quincenal…), con día fijo de la semana opcional (p. ej. sábados), o cada N meses de calendario | `config_modalidades` |
| Tramos por modalidad: desde qué monto, tasa, n.º de cuotas y redondeo | `config_tramos` |
| Puntualidad: hora máxima, días de tolerancia, cuántos pagos tarde para cada calificación | `config_puntualidad` |
| Calificación: aumento sugerido y si se renueva, por cada calificación | `config_calificacion` |
| Tipos de movimiento de caja y su signo | `config_tipos_caja` |

- Los valores que aparecen en este documento (17:00, 20 %, 8 cuotas, +S/ 100…) son los del libro v13 de Daniel. Son **la
  configuración de su cuenta** y sirven de ejemplo y de prueba; no son valores de la casa.
- Una cuenta nueva **empieza sin modalidades ni tasas** y no puede entregar préstamos hasta configurar al menos una modalidad con un
  tramo que empiece en S/ 0. La configuración se valida entera y se avisan todos los problemas juntos.
- Cambiar las reglas **no recalcula los préstamos ya entregados**: cada préstamo guarda su propio plan.
- Una cuenta nunca ve ni modifica las reglas de otra: todo acceso lleva `tenant_id` y la base rechaza mezclar cuentas.
- Las reglas de v13 (Daniel) viven en el código solo como dato de prueba (`tests/Fixtures/ReglasV13.php`); en producción serán la
  configuración de su cuenta, cargada al migrar.

## 1. Plan de pago

| Regla | Detalle | Prueba |
|---|---|---|
| Tramo | Para la modalidad del préstamo se usa el tramo con el mayor «desde» que no pase el monto. S/ 499.99 usa el tramo de menos de 500; S/ 500 usa el siguiente. Sin tramo, **el préstamo no se crea**. | T5, T23 |
| Total | `monto × (1 + tasa)`, redondeado al céntimo. La tasa se guarda en puntos básicos (2000 = 20 %). | T1–T3 |
| Cuota normal | `total ÷ n` hacia abajo. Redondeo 0: a céntimos. Redondeo N: a múltiplos de N soles. | T3, T4 |
| 1.ª cuota | `total − (n − 1) × cuota normal`. Lo que sobra por redondeo se suma a la primera. Ej.: S/ 500 semanal → 1 × 65.00 + 11 × 60.00. | T3 |
| Fecha de la 1.ª cuota | Sin día fijo: entrega + cada N días. Con día fijo: el próximo día indicado **estrictamente posterior** a la entrega (un préstamo en sábado empieza el sábado siguiente). Modalidad en meses: entrega + N meses, el mismo día del mes. | T1, T2, T6 |
| Fechas siguientes | Cada N días desde la 1.ª. En meses, siempre contadas desde la entrega: 31/01 → 28/02 → 31/03 (el recorte de un mes corto no se arrastra). | T1–T3, reglas por cuenta |
| Se guarda | El plan se calcula al entregar y **se guarda en el préstamo**. Cambiar un tramo después no recalcula préstamos entregados. | — |

## 2. Pagos y cuotas

- **Asignación (FIFO)**: los pagos de un préstamo se ordenan por fecha (y hora; a igualdad, por orden de registro) y cada uno cubre desde la
  cuota más antigua pendiente. El n.º de cuota de un pago es el de la **más antigua** que cubre, aunque pague dos a la vez. Es un valor
  **derivado**: se recalcula siempre; anular un pago hace que los siguientes pasen a cubrir las cuotas anteriores. (T7, T12, T13)
- **Cuota esperada**: lo que falta para completar esa cuota. **Control del monto**: `✔ Monto OK`, `Monto distinto a la cuota` o
  `⚠ Pagó más que el saldo`. (T12, T14)
- **Pagos anulados** no cuentan en nada. Nunca se borra un pago.

## 3. Puntualidad de cada pago

Cuatro estados, comparando la fecha del pago con la de la cuota que cubre:

| Condición | Estado |
|---|---|
| Días de retraso **mayores** que la tolerancia | DESPUÉS DE LA FECHA |
| Hay retraso dentro de la tolerancia, **o** paga el mismo día pasada la hora máxima | DESPUÉS DE LA HORA |
| Paga antes de la fecha | ADELANTADO |
| Paga el mismo día hasta la hora máxima | PUNTUAL |

- Hora máxima por defecto **17:00**; a las 17:00 en punto todavía es puntual. Tolerancia por defecto 0 días.
- «Pasada la hora» se decide por la **hora real del pago**. Para pagos migrados del Excel, que no tienen hora, manda la marca manual
  de la columna E (ninguno del v13 la tiene en «SÍ»). (T8–T11)

## 4. Estado del préstamo y KPI

| Regla | Detalle |
|---|---|
| Cuotas vencidas | Cuotas con fecha **anterior** a hoy. La que vence hoy todavía no está vencida: lo estará mañana. |
| Atraso | `acumulado(cuotas vencidas) − cobrado`, mínimo 0, donde `acumulado(k) = 1.ª + (k − 1) × normal`. (T17) |
| Estado | CANCELADO si el saldo es ≤ 0 · PROGRAMADO si hoy es anterior a la entrega · ATRASADO si hay atraso · AL DÍA. (T15–T17) |
| Cuotas pagadas | Cuotas completas que cubre lo cobrado (la 1.ª puede ser distinta). |
| Próxima fecha | 1.ª fecha + cuotas pagadas × cada N días; vacía si está cancelado. |
| Capital en la calle | `saldo ÷ (1 + tasa)`; 0 si está cancelado o programado. |
| Ganancia cobrada | `cobrado × tasa ÷ (1 + tasa)`. **Proporcional**, no «primero el capital». |
| Patrimonio aprox. | Caja + por cobrar. Cuenta intereses que aún no se ganan. |

## 5. Calificación y renovación

- Se cuentan **todos los pagos del cliente** (todos sus préstamos, sin ventana de tiempo): `n_fecha` = después de la fecha, `n_hora` = después de la hora.
- `n_fecha ≥ 3` → NO RENOVAR · `n_fecha ≥ 1` → DESPUÉS DE LA FECHA · `n_hora ≥ 1` → DESPUÉS DE LA HORA · si no, PUNTUAL. Sin pagos, sin calificación. (T18)
- La **calificación manual** del prestamista, si existe, manda sobre la automática.
- **Renovación sugerida**: sin calificación → el mismo monto · calificación que no renueva → 0 · si no → monto + aumento (PUNTUAL: + S/ 100; las demás: + 0).
- Los pagos ADELANTADOS cuentan como puntuales.

## 6. Caja

`caja = movimientos manuales (con su signo) + pagos cobrados − préstamos entregados`

- Los cobros y los desembolsos **no** se anotan en la caja: salen de los pagos y de los préstamos.
- Tipos de movimiento: suma (saldo inicial, aporte de capital, otro ingreso), resta (retiro, gasto, pago a inversionista) o **solo se anota** (por confirmar).
- **Caja por período**: el saldo inicial es la caja de todo lo anterior a «desde»; nunca se arrastra a mano. (T21)
- La caja negativa del v13 (−S/ 422.50 al 30/09) es **aritmética**: 1,000 + 500 + 500 + 75 + 1,502.50 − 4,000. Falta registrar al menos S/ 422.50 de capital.
- **Pendiente de la Fase 1**: arqueo diario (efectivo contado frente a caja del sistema). El «control = 0» del Excel compara la fórmula consigo misma y no detecta faltantes.

## 7. TEA equivalente (referencia)

Tasa diaria que iguala el monto entregado con el valor presente de las cuotas, llevada a 365 días. Tramos del v13 entregados un lunes:
C3D 20 % en 8 cuotas ≈ 15,528 % · semanal 40 % en 8 ≈ 7,986 % · semanal 45 % en 12 ≈ 2,736 %. Se muestra como referencia; cada prestamista
es responsable de cumplir los topes vigentes del BCRP (a verificar con un abogado antes del piloto).

## 8. Desviaciones intencionales frente al Excel v13

Todo lo que el motor hace distinto del libro. Ninguna cambia un resultado del v13 (la paridad es total); cambian casos que el libro no cubre.

| # | Excel v13 | PrestaCloud | Motivo |
|---|---|---|---|
| D1 | Cuotas «exactas» con decimales largos | Se truncan a céntimos; la 1.ª absorbe la diferencia | Un cobro real se paga en céntimos |
| D2 | «Después de la hora» por marca manual (columna E) | Por la hora real del pago; la marca manual, si existe, manda | Es el objetivo del sistema |
| D3 | `⚠ SIN TRAMO` en la hoja | El préstamo no se puede crear | Evita préstamos sin plan |
| D4 | ID = `P` + n.º de fila | Código único por cuenta, independiente del orden | Ordenar o borrar filas ya no rompe vínculos |
| D5 | Ciclo = orden de las filas | Ciclo = orden por fecha y código | Mismo resultado salvo filas desordenadas |
| D6 | Importes sin redondear en capital y ganancia | Redondeo al céntimo | Dinero siempre en céntimos |
| D7 | Pagos se borran de la hoja | Pagos se anulan con auditoría | Historial completo |
| D8 | Sin TEA | TEA equivalente en el simulador | Transparencia |

## 9. Decisiones que siguen abiertas

1. **Calificación sin ventana de tiempo**: un cliente con 3 retrasos hace años queda en NO RENOVAR para siempre. ¿Se limita a los últimos N meses?
2. **Renovación sugerida**: hoy se calcula sobre el **último** préstamo del cliente. En el Excel era por cada préstamo.
3. **Refinanciamiento**: el Excel no descuenta el saldo viejo al renovar. ¿Se agrega?
4. **Aumento de S/ 100 que cambia de tramo**: pasar de S/ 450 a S/ 550 cambia la tasa y las cuotas; la pantalla debe avisarlo.
5. **¿Cambiar la hora máxima o la tolerancia reclasifica los pagos pasados?** Hoy la puntualidad se calcula siempre con las reglas
   vigentes (como el Excel), así que sí. La alternativa es congelarla al registrar cada pago. Conviene decidirlo antes del piloto.

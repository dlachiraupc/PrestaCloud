# PrestaCloud — Plan por fases y validación

**Versión 1.1 · 27 de octubre de 2026**
Complementa la *Propuesta del Sistema v1.0* y el *Mockup v1.0*. Se apoya en la lectura del libro PRESTAMOS v13 (17 préstamos, 46 pagos, 13 clientes, 4 movimientos de caja) y en el documento de validación del Excel (casos T1–T24).

---

## 1. Principios del plan

1. **El motor de cálculo va antes que las pantallas.** Cuotas, fechas, asignación de pagos, puntualidad, caja y calificación se construyen como una biblioteca PHP pura (sin base de datos ni HTTP) y se prueban sola. Si el motor es correcto, las pantallas solo lo muestran.
2. **El Excel v13 es el oráculo.** Sus valores guardados son una foto congelada del 30/09/2026. La app debe reproducirlos celda por celda antes de que se escriba una sola pantalla.
3. **Multiempresa desde el primer día.** Toda tabla de negocio lleva `tenant_id` desde la Fase 1, con un único tenant sembrado (el de Daniel). Convertir un sistema monoempresa a multiempresa después es la forma más común de provocar fugas entre cuentas.
4. **Cada fase termina en una compuerta (gate).** Una fase no se da por cerrada por haber terminado de programar, sino por cumplir un criterio medible. Sin compuerta aprobada no se empieza la siguiente.
5. **Nunca se borra, se anula.** Pagos, préstamos y movimientos de caja se anulan con auditoría. Todo lo derivado (asignación de pagos, puntualidad, saldos) se recalcula.
6. **Dinero sin decimales flotantes.** Importes en céntimos enteros o `DECIMAL`, nunca `float`. El reloj es inyectable y la zona horaria es `America/Lima`, para poder probar fechas.

## 2. Supuestos (decisiones tomadas por defecto)

Son las decisiones que quedaron abiertas en el análisis previo. Se asumen así salvo que Daniel indique lo contrario.

| # | Tema | Supuesto |
|---|---|---|
| S1 | Pagos y cuotas | La asignación de un pago a las cuotas se **deriva** por orden de antigüedad (FIFO), igual que el Excel; nadie la edita. La puntualidad es **un valor por pago**, medido contra la cuota más antigua que ese pago cubre. Hay **cuatro estados**: PUNTUAL, ADELANTADO, DESPUÉS DE LA HORA, DESPUÉS DE LA FECHA. |
| S2 | Caja | La caja es un **valor calculado**: movimientos manuales + pagos − préstamos entregados. Cobros y desembolsos no se copian a `caja_movimientos`. Se añade un **arqueo diario** (efectivo contado y diferencia registrada), porque el "control = 0" del Excel es una identidad y no detecta faltantes. |
| S3 | Cliente activo | Cliente con al menos un préstamo AL DÍA o ATRASADO. La cartera actual de Daniel sería 11 de 15. |
| S4 | Tasas por defecto | Las cuentas nuevas **no** heredan 20 % / 40 % / 45 %. Arrancan con una configuración de ejemplo, editable. El simulador muestra la **TEA equivalente** de cada préstamo. |
| S5 | Alcance | Entran: simulador y "dinero que regresa mañana". Se difieren: contratos PDF con firma, documentos extra, ficha extendida (GPS, vivienda, ingresos), score de estrellas. La lista negra, si llega, es privada por prestamista. |
| S6 | Base de código | Se reutiliza el MVC de EmpresasCloud. Si no está disponible, se usa un router y PDO mínimos en PHP 8.2. |
| S7 | Datos de prueba | Los datos de clientes reales **no** van al repositorio. Los tests usan datos ficticios con la misma estructura. |

## 3. Vista general

| Fase | Objetivo | Duración estimada | Compuerta (resumen) |
|---|---|---|---|
| 0 | Motor de cálculo, reglas y dataset dorado | 2–3 semanas | 100 % de casos T1–T24 y 100 % de celdas del v13 reproducidas |
| 1 | MVP de uso propio, multiempresa desde el día 1 | 3–4 semanas + 2 de uso paralelo | 10 días hábiles sin diferencias frente al Excel |
| 2 | Registro de terceros, planes y límites | 3–4 semanas | Aislamiento entre cuentas demostrado con pruebas automáticas |
| 3 | Piloto con 5–10 prestamistas | 1 semana de preparación + 4 de piloto | Uso real sostenido y disposición a pagar |
| 4 | Lanzamiento con cobro y plan Negocio | 4–6 semanas | Cobro probado de punta a punta y revisión legal |

Total aproximado: **17–22 semanas** a tiempo parcial con una persona desarrollando. Es 2–4 semanas más que la propuesta v1.0, a cambio de llegar al uso real con el cálculo ya demostrado. Las duraciones se confirman al cerrar la Fase 0.

---

## 4. Fases

### Fase 0 — Motor de cálculo y dataset dorado

**Entrada.** Supuestos S1–S7 aceptados. Daniel congela una copia del v13 como oráculo (no se vuelve a tocar) y trabaja sus correcciones en una copia aparte (v14).

**Entregables**
- Entorno de desarrollo: PHP 8.2, Composer y MySQL 8. En la máquina actual no hay PHP instalado, pero sí Docker (hay que comprobar que el servicio esté activo); alternativa: Laragon o XAMPP. Repositorio Git con integración continua que ejecute los tests.
- Documento `REGLAS_DE_NEGOCIO` con las reglas en forma exacta: elección de tramo, total, cuota normal y primera cuota, redondeo, fecha de primera cuota, cuotas vencidas, atraso, estado del préstamo, FIFO, puntualidad, calificación, renovación sugerida, caja, y los KPI (capital en la calle = saldo ÷ (1 + tasa); ganancia cobrada = cobrado × tasa ÷ (1 + tasa)).
- Biblioteca `core` en PHP puro con esas reglas, con reloj inyectable y dinero en céntimos.
- **Modelo de datos final** (`schema.sql`) con las correcciones del análisis: `tenant_id` también en `cuotas`; tabla derivada `pago_cuotas`; `calificacion_manual` separada de la automática; hora del pago opcional más bandera "después de la hora"; clave de idempotencia en `pagos`; `codigo` único por tenant con contador atómico.
- Suite de pruebas y dataset dorado (ver abajo).

**Cómo se valida**

| Tipo de prueba | Qué comprueba | Quién |
|---|---|---|
| Casos T1–T24 del documento de validación, tal cual | Reglas del negocio: tramos y fronteras (499.99 / 500), sábado, pagos parciales, dos cuotas en un pago, sobrepago, cancelado, programado, atraso, calificación, diminutivo | Automática |
| Casos nuevos para modalidades que el Excel no tiene (diario, quincenal, mensual) y para céntimos, calculados a mano | Que el motor generaliza más allá de C3D y SEMANAL | Automática; Daniel revisa los resultados esperados |
| **Paridad con el v13** | Se cargan los 17 préstamos, 46 pagos, 4 movimientos y 13 clientes en el motor con el reloj fijado en **30/09/2026** (la fecha efectiva de los valores guardados: P001 muestra 1 día de atraso sobre un vencimiento del 29/09). Se compara cada celda calculada: PRESTAMOS R–AD, PAGOS H–N, CLIENTES I–N y Q–U, INICIO C6, C9, C10, F6–F9 y F11 | Automática |
| Pruebas de propiedades | Σ cuotas = total; Σ asignación FIFO = Σ pagos; anular un pago y reaplicar da el mismo resultado; la identidad de caja se cumple; sin `float` en cálculos de dinero | Automática |

Nota sobre el oráculo: algunas celdas del v13 no traen valor guardado (las que valen 0 o vacío). Para no dejar huecos, Daniel puede generar una copia del v13 donde `TODAY()` se reemplaza por `DATE(2026,9,30)` y se pegan valores. Esa copia es la que se usa como oráculo.

**Desviaciones intencionales.** Todo lo que el motor haga distinto del Excel se registra en una lista y Daniel la aprueba. Ya se conocen dos: las cuotas "exactas" se truncan a céntimos (el Excel deja decimales largos) y "después de la hora" se decide por la hora real del pago (en el v13 ningún pago lo tiene marcado, así que no hay historia con la que comparar; se valida con tests, no con datos).

**Criterio de salida.** 100 % de los casos pasan; 100 % de las celdas del oráculo coinciden o están en la lista de desviaciones aprobadas; integración continua en verde; `schema.sql` y `REGLAS_DE_NEGOCIO` aprobados por Daniel.

---

### Fase 1 — MVP de uso propio

**Entrada.** Fase 0 aprobada. Tareas de Daniel: cargar en el v14 los cobros de octubre (P011–P017 y la última cuota de P001), e identificar el aporte de capital que falta (al menos S/ 422.50). La hora máxima de pago del v13 ya es 17:00; la propuesta v1.0 decía 00:00 por error.

**Entregables**
- Base de datos con `tenant_id` en todas las tablas de negocio y una capa de acceso que lo aplica siempre. Un tenant sembrado.
- Inicio de sesión (contraseñas con hash, bloqueo por intentos, CSRF, escape de salida).
- Clientes, préstamos con **simulador** (muestra cuotas, ganancia y TEA), cuotas generadas por el motor, cobranza del día, registro de pago (fecha y hora automáticas, editables con auditoría; reintento seguro con clave de idempotencia para cuando la señal sea mala), anulación de pagos.
- Caja con movimientos manuales y arqueo diario; panel de inicio; ficha del cliente; configuración de modalidades, tramos, puntualidad y calificación; auditoría.
- "Dinero que regresa mañana" (cuotas del día siguiente).
- **Script de migración del v13**: idempotente, mantiene el orden del Excel (fecha y luego fila), importa sin aplicar el bloqueo "no prestar más que la caja" (el v13 ya tiene caja negativa) y lee solo el ID del préstamo, no la etiqueta del desplegable (hay etiquetas viejas, como P008 con "c2" cuando su ciclo es 1).
- PWA instalable en el celular, despliegue en staging y producción, copia de seguridad diaria.

**Cómo se valida**

| Tipo de prueba | Qué comprueba | Quién |
|---|---|---|
| Migración contra el oráculo | Tras migrar, el panel de la app coincide con el del v13 al 30/09: caja −422.50, por cobrar 3,677.50, capital en la calle 2,787.50, ganancia cobrada 290, 13 vigentes, 1 atrasado de S/ 30 | Automática |
| **Uso paralelo con el Excel** | Durante 10 días hábiles, cada pago se registra en la app (desde el celular) y en el v14. Cada cierre de día se comparan caja, por cobrar, cobrado hoy, atrasos y calificaciones. Toda diferencia se clasifica como error de la app, error del Excel, error de digitación o cambio intencional | Daniel |
| Prueba de campo | Una ruta de cobro real, con mala señal. Medir tiempo para registrar un pago (objetivo: 10 segundos desde la lista hasta la confirmación) y que un reintento no duplique el pago | Daniel |
| Respaldo | Restaurar la copia de seguridad en staging y comparar totales con producción | Automática + Daniel |
| Seguridad básica | Intentos de inyección, XSS, CSRF, fuerza bruta contra el login; revisión de seguridad del código antes de publicar | Automática + revisión |
| Rendimiento | 50 000 pagos sintéticos: el panel carga en menos de 1 segundo en el hosting real | Automática |

**Criterio de salida.** 10 días hábiles seguidos con **cero** diferencias clasificadas como error de la app; restauración de respaldo comprobada; Daniel decide dejar de usar el Excel y lo archiva en solo lectura.

---

### Fase 2 — Multiempresa real y planes

**Entrada.** Fase 1 aprobada y la app en uso diario.

**Entregables**
- Registro autoservicio (cuenta → negocio → contraseña), superadministrador, tabla de planes y límites, prueba de 14 días, paso a Gratis sin pérdida de datos, modo solo lectura al exceder el límite, avisos por correo antes del vencimiento.
- Cuentas nuevas con configuración de ejemplo editable (S4).
- Funciones de pago: recordatorio por WhatsApp (enlace con mensaje escrito), constancia de pago en PDF (con la leyenda "no es comprobante SUNAT"), portal del cliente por enlace con token, exportación a Excel.
- Borrador de términos de servicio, contrato de encargo y política de privacidad.

**Cómo se valida** (todo automatizado y específico)

| Prueba | Qué comprueba |
|---|---|
| Capa de datos que impide omitir el tenant | Una consulta de negocio sin `tenant_id` lanza una excepción |
| **Prueba de dos cuentas** | Para cada punto de entrada (pantallas, API, PDF, portal, exportaciones, archivos), la cuenta B no puede leer, editar ni anular nada de la cuenta A: respuesta 404 |
| Análisis estático | Ninguna consulta SQL sobre una tabla con `tenant_id` omite el filtro |
| Límites y planes | El cliente activo n.º 16 se bloquea en Gratis; al terminar la prueba la cuenta queda en solo lectura y no se borra nada |
| Reloj inyectado | Día 14, día 15, aviso a 5 días del vencimiento y 3 días de gracia |
| Seguridad del portal | Tokens no adivinables (al menos 128 bits), límite de intentos, el token no sirve para otras cuentas |
| Generalización del motor | Casos con las otras modalidades configurables: diario, quincenal, mensual |

**Compuerta legal (antes de abrir a terceros).** Consulta con un abogado sobre: tasas e interés máximo, términos y contrato de encargo, y qué se muestra al usuario sobre la TEA.

**Criterio de salida.** Todas las pruebas anteriores en verde; revisión de seguridad independiente del aislamiento entre cuentas; compuerta legal cumplida.

---

### Fase 3 — Piloto

**Entrada.** Fase 2 aprobada; términos y política publicados; inscripción del banco de datos iniciada.

**Entregables**
- Alta asistida de 5 a 10 prestamistas conocidos, con soporte por WhatsApp.
- Medición de eventos: registro, primer cliente, primer préstamo, cinco préstamos, pagos por semana, límite alcanzado.
- Panel del superadministrador con esas métricas.
- Entrevista corta semanal a cada piloto y registro de problemas de usabilidad.

**Cómo se valida.** Umbrales propuestos, ajustables por Daniel:

| Medida | Umbral propuesto |
|---|---|
| Pilotos que completan al menos 2 semanas de uso | ≥ 5 |
| Activación (cinco préstamos en 7 días) | ≥ 60 % de los registrados |
| Retención en la semana 4 | ≥ 50 % |
| Incidentes de aislamiento entre cuentas | 0 |
| Disputas de cálculo sin explicar | 0 |
| Pilotos con reglas distintas a las de Daniel (otra modalidad o tasa) cuyos cálculos coinciden con su propio cuaderno | ≥ 2 |
| Disposición a pagar Pro al precio referencial | ≥ 2 pilotos lo declaran; mejor, un pago simbólico por adelantado |
| Principales 3 bloqueos de usabilidad | Corregidos |

**Criterio de parada.** Si pasadas 4 semanas menos de 3 pilotos usan la app de forma activa, o ninguno acepta pagar, no se sigue a la Fase 4: se replantea el producto (por ejemplo, quedarse como herramienta propia o cambiar el modelo de precio).

---

### Fase 4 — Lanzamiento

**Entrada.** Piloto aprobado y precios fijados con datos.

**Entregables**
- Pasarela de pagos (Culqi, Izipay o Mercado Pago) con renovación automática; activación manual por Yape/Plin/transferencia como alternativa.
- Plan Negocio: cobradores y rutas, **rendición de efectivo del cobrador** (el dinero cobrado entra a la caja del dueño cuando el cobrador lo entrega), cierre por cobrador, inversionistas con la fórmula de rendimiento definida de antemano, auditoría visible.
- Landing comercial; facturación de las suscripciones con el ERP de EmpresasCloud.

**Cómo se valida**

| Prueba | Qué comprueba |
|---|---|
| Pasarela en sandbox, punta a punta | Alta, cobro, fallo, reintento, baja y reembolso; webhooks firmados e idempotentes |
| Matriz de permisos por rol | El cobrador no ve caja ni ganancias; el inversionista solo ve lo suyo |
| Rendición de cobradores | Efectivo en poder del cobrador + rendiciones = cobros registrados |
| Carga | 100 cuentas sintéticas en el hosting real |
| Respaldo | Retención de 30 días y restauración probada |
| Lista legal | Términos con contrato de encargo, política de privacidad, consentimiento, inscripción en el registro de datos personales, procedimiento ARCO y de incidentes |

**Criterio de salida.** Todas las pruebas en verde, y un **lanzamiento suave** con 20–30 cuentas antes de cualquier campaña en redes.

---

## 5. Qué cambia respecto a la Propuesta v1.0

1. **Nueva Fase 0** de motor y dataset dorado, antes de las pantallas. La "Fase 0 — Diseño" de la propuesta queda absorbida.
2. **`tenant_id` desde la Fase 1**, no en la Fase 2. La Fase 2 solo añade registro, planes y límites.
3. **Uso paralelo obligatorio** antes de abandonar el Excel.
4. **Reintento seguro y registro con mala señal** desde el MVP (clave de idempotencia).
5. **Arqueo de caja** en lugar de un "control = 0" que siempre da cero.
6. **Piloto antes del cobro.** La pasarela y el plan Negocio pasan a la Fase 4, después de comprobar demanda.
7. **Compuerta legal explícita** antes de abrir a terceros, no solo antes del lanzamiento.

## 6. Qué necesita Daniel en cada fase

| Fase | Tarea |
|---|---|
| 0 | Congelar el v13; aprobar reglas, modelo de datos y lista de desviaciones; confirmar si el MVC de EmpresasCloud está disponible |
| 1 | Cargar los cobros de octubre; identificar el aporte de capital faltante; 10 días de uso paralelo |
| 2 | Cita con abogado; elegir nombre y dominio |
| 3 | Elegir a los 5–10 pilotos; entrevistas semanales |
| 4 | Contratar la pasarela; definir la fórmula de rendimiento de inversionistas |

## 7. Riesgos que este orden reduce

| Riesgo | Cómo lo reduce el plan |
|---|---|
| Errores de cálculo frente al Excel | Paridad celda por celda antes de las pantallas, y uso paralelo después |
| Fuga entre cuentas | `tenant_id` desde el día 1, capa de datos que lo exige y prueba de dos cuentas |
| Pérdida de información | Anulación en lugar de borrado, restauración de respaldo probada |
| Construir un producto que nadie paga | Piloto con criterio de parada antes de invertir en cobro y plan Negocio |
| Exposición legal por tasas y datos personales | Compuerta legal antes del piloto; configuración por defecto neutral; TEA visible |

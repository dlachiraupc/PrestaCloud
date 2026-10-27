// Genera prestacloud-app/tests/fixtures/v13_golden.json a partir de PRESTAMOS_v13.xlsx.
//
// Uso:   node tools/generar_golden_v13.mjs <ruta/PRESTAMOS_v13.xlsx> [salida.json]
//
// Lee los VALORES GUARDADOS del libro (no recalcula nada): son el oráculo con el que se compara
// el motor de cálculo. Los guarda tal cual estaban el día del último recálculo (se infiere "hoy").
//
// Privacidad (Ley N.º 29733): el JSON NO lleva nombres, apodos, teléfonos ni referencias.
// Los clientes salen solo por código (C001…), los préstamos por ID (P001…). El .xlsx nunca se versiona.
import fs from "node:fs";
import JSZip from "jszip";

const [, , entrada, salida = "prestacloud-app/tests/fixtures/v13_golden.json"] = process.argv;
if (!entrada) {
  console.error("Uso: node tools/generar_golden_v13.mjs <PRESTAMOS_v13.xlsx> [salida.json]");
  process.exit(1);
}

const zip = await JSZip.loadAsync(fs.readFileSync(entrada));
const leer = async (ruta) => (await zip.file(ruta).async("string"));
const decodificar = (s) =>
  s.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(+n)).replace(/&amp;/g, "&");

/* ---- hojas por nombre ---- */
const wbXml = await leer("xl/workbook.xml");
const relsXml = await leer("xl/_rels/workbook.xml.rels");
const rels = Object.fromEntries([...relsXml.matchAll(/<Relationship [^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/g)].map((m) => [m[1], m[2]]));
const hojas = Object.fromEntries([...wbXml.matchAll(/<sheet [^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/g)].map((m) => [m[1], "xl/" + rels[m[2]]]));

/* ---- cadenas compartidas ---- */
const compartidas = [];
if (zip.file("xl/sharedStrings.xml")) {
  const sx = await leer("xl/sharedStrings.xml");
  for (const m of sx.matchAll(/<si>([\s\S]*?)<\/si>/g)) {
    compartidas.push(decodificar([...m[1].matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)].map((t) => t[1]).join("")));
  }
}

async function hoja(nombre) {
  const xml = await leer(hojas[nombre]);
  const celdas = new Map();
  for (const m of xml.matchAll(/<c r="([A-Z]+)(\d+)"([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
    const [, col, fila, attrs, cuerpo = ""] = m;
    const tipo = (attrs.match(/ t="(\w+)"/) || [])[1] || "n";
    const v = (cuerpo.match(/<v>([\s\S]*?)<\/v>/) || [])[1];
    let valor = null;
    if (tipo === "inlineStr") valor = decodificar([...cuerpo.matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)].map((t) => t[1]).join(""));
    else if (v === undefined) valor = null;
    else if (tipo === "s") valor = compartidas[+v];
    else if (tipo === "str" || tipo === "e") valor = decodificar(v);
    else if (tipo === "b") valor = v === "1";
    else valor = v === "" ? null : Number(v);
    if (valor === "") valor = null;
    celdas.set(col + fila, valor);
  }
  return (ref) => celdas.get(ref) ?? null;
}

/* ---- conversiones ---- */
const EPOCH = Date.UTC(1899, 11, 30);
const fecha = (n) => (typeof n === "number" ? new Date(EPOCH + n * 86400000).toISOString().slice(0, 10) : null);
const cent = (n) => (typeof n === "number" ? Math.round(n * 100) : null);
const bp = (n) => (typeof n === "number" ? Math.round(n * 10000) : null);
const hora = (n) => {
  if (typeof n !== "number") return null;
  const m = Math.round((n % 1) * 1440);
  return String(Math.floor(m / 60)).padStart(2, "0") + ":" + String(m % 60).padStart(2, "0");
};

const cfg = await hoja("CONFIG");
const pre = await hoja("PRESTAMOS");
const pag = await hoja("PAGOS");
const cli = await hoja("CLIENTES");
const caj = await hoja("CAJA");
const ini = await hoja("INICIO");

/* ---- configuración ---- */
const config = {
  modalidades: [],
  tramos: [],
  tiposCaja: {},
  calificacion: [],
  puntualidad: {
    horaMaximaEnCelda: hora(cfg("C50")),
    toleranciaDias: cfg("C51"),
    nHora: cfg("C52"),
    nFecha: cfg("C53"),
    nNoRenovar: cfg("C54"),
  },
};
for (let f = 6; f <= 10; f++) if (cfg("B" + f)) config.modalidades.push({ codigo: cfg("B" + f), cadaDias: cfg("C" + f), diaFijo: cfg("D" + f) });
for (let f = 14; f <= 23; f++) if (cfg("B" + f)) config.tramos.push({ modalidad: cfg("B" + f), desde: cent(cfg("C" + f)), tasaBp: bp(cfg("D" + f)), nCuotas: cfg("E" + f), redondeo: cfg("F" + f) ?? 0 });
for (let f = 28; f <= 37; f++) if (cfg("B" + f)) config.tiposCaja[cfg("B" + f)] = cfg("C" + f);
for (let f = 41; f <= 46; f++) if (cfg("B" + f)) config.calificacion.push({ etiqueta: cfg("B" + f), aumento: cent(cfg("C" + f)), renueva: cfg("D" + f) === "SI" });

/* ---- préstamos ---- */
const prestamos = [];
for (let f = 6; f <= 305; f++) {
  if (!pre("C" + f)) continue;
  prestamos.push({
    codigo: pre("B" + f),
    cliente: pre("AI" + f),
    modalidad: pre("E" + f),
    fecha: fecha(pre("F" + f)),
    prestado: cent(pre("G" + f)),
    esperado: {
      ciclo: pre("I" + f), tasaBp: bp(pre("J" + f)), nCuotas: pre("K" + f), total: cent(pre("L" + f)),
      cuotaPrimera: cent(pre("M" + f)), cuotaNormal: cent(pre("N" + f)), plan: pre("O" + f),
      fechaPrimera: fecha(pre("P" + f)), fechaFin: fecha(pre("Q" + f)),
      cobrado: cent(pre("R" + f)), saldo: cent(pre("S" + f)),
      cuotasPagadas: pre("T" + f), cuotasVencidas: pre("U" + f), atraso: cent(pre("V" + f)),
      proximaFecha: fecha(pre("W" + f)), diasAtraso: pre("X" + f), estado: pre("Y" + f),
      gananciaTotal: cent(pre("Z" + f)), gananciaCobrada: cent(pre("AA" + f)), capitalEnLaCalle: cent(pre("AB" + f)),
      calificacion: pre("AC" + f), renovacionSugerida: cent(pre("AD" + f)),
    },
  });
}

/* ---- "hoy" con el que el libro calculó sus valores (TODAY() guardado) ---- */
const atrasado = prestamos.find((p) => p.esperado.diasAtraso > 0 && p.esperado.proximaFecha);
if (!atrasado) throw new Error("No se pudo inferir la fecha de cálculo: ningún préstamo atrasado.");
const hoyDias = Math.round((Date.parse(atrasado.esperado.proximaFecha) - EPOCH) / 86400000) + 0; // días desde 1899-12-30
const hoy = new Date(Date.parse(atrasado.esperado.proximaFecha) + atrasado.esperado.diasAtraso * 86400000).toISOString().slice(0, 10);
// Contraste: ningún préstamo con fecha posterior a "hoy" puede figurar como AL DÍA/ATRASADO.
for (const p of prestamos) {
  if (p.fecha > hoy && p.esperado.estado !== "PROGRAMADO") throw new Error(`Inconsistencia al inferir hoy=${hoy} con ${p.codigo}`);
}

/* ---- pagos (en el orden de las filas) ---- */
const pagos = [];
for (let f = 6; f <= 1505; f++) {
  if (!pag("B" + f) || !pag("D" + f)) continue;
  pagos.push({
    fila: f,
    fecha: fecha(pag("B" + f)),
    prestamo: pag("O" + f),
    cliente: pag("R" + f),
    monto: cent(pag("D" + f)),
    despuesDeHora: pag("E" + f) === "SÍ" ? true : null,
    esperado: {
      puntualidad: pag("H" + f), diasTarde: pag("I" + f), cuota: pag("J" + f), vencia: fecha(pag("K" + f)),
      cuotaEsperada: cent(pag("L" + f)), saldoTrasPago: cent(pag("M" + f)), control: pag("N" + f), pagadoAntes: cent(pag("S" + f)),
    },
  });
}

/* ---- caja ---- */
const caja = [];
for (let f = 6; f <= 305; f++) {
  if (!caj("B" + f) || !caj("C" + f)) continue;
  caja.push({ fecha: fecha(caj("B" + f)), tipo: caj("C" + f), monto: cent(caj("D" + f)) });
}

/* ---- clientes: solo código y resultados ---- */
const clientes = [];
for (let f = 6; f <= 205; f++) {
  if (!cli("B" + f)) continue;
  clientes.push({
    codigo: cli("B" + f),
    calificacionManual: cli("G" + f),
    esperado: {
      nPrestamos: cli("I" + f), vigentes: cli("J" + f), deuda: cent(cli("K" + f)), atraso: cent(cli("L" + f)), pagado: cent(cli("M" + f)),
      situacion: cli("N" + f), puntuales: cli("Q" + f), despuesHora: cli("R" + f), despuesFecha: cli("S" + f),
      calificacionAuto: cli("T" + f), calificacionUsada: cli("U" + f),
    },
  });
}

/* ---- panel INICIO ---- */
const inicio = {
  cajaHoy: cent(ini("C6")), cobradoHoy: cent(ini("C8")), prestamosAtrasados: ini("C9"), montoAtraso: cent(ini("C10")),
  prestamosVigentes: ini("F6"), porCobrar: cent(ini("F7")), capitalEnLaCalle: cent(ini("F8")), gananciaCobrada: cent(ini("F9")),
  programados: cent(ini("F10")), patrimonio: cent(ini("F11")),
  periodo: {
    desde: fecha(ini("C14")), hasta: fecha(ini("C15")), saldoInicial: cent(ini("C16")), cobros: cent(ini("C17")),
    aportes: cent(ini("C18")), prestamos: cent(ini("C19")), retiros: cent(ini("C20")), saldoFinal: cent(ini("C21")), control: cent(ini("C22")),
  },
};

const salidaJson = {
  meta: {
    fuente: "PRESTAMOS_v13.xlsx (valores guardados, sin recalcular)",
    hoy,
    nota: "Solo códigos y cifras. Sin nombres, apodos, teléfonos ni referencias de clientes. Generado con tools/generar_golden_v13.mjs.",
    conteos: { clientes: clientes.length, prestamos: prestamos.length, pagos: pagos.length, movimientosCaja: caja.length },
  },
  config, caja, clientes, prestamos, pagos, inicio,
};
fs.mkdirSync(salida.replace(/[\\/][^\\/]+$/, ""), { recursive: true });
fs.writeFileSync(salida, JSON.stringify(salidaJson, null, 1) + "\n");
console.log(`Escrito ${salida}: hoy=${hoy} · ${clientes.length} clientes · ${prestamos.length} préstamos · ${pagos.length} pagos · ${caja.length} movimientos de caja`);

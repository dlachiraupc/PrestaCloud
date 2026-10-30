// Genera los .env de dev y qa con contraseñas aleatorias de base de datos.
//
// Uso:   node tools/generar_env.mjs [carpeta]        (por defecto: entornos/, que git ignora)
//
// No sobrescribe archivos que ya existen. Los .env llevan contraseñas: viven solo en el equipo y se suben al hosting a mano
// (cPanel → Administrador de archivos), nunca al repositorio. Los nombres de base y de usuario deben coincidir con los que se
// creen en cPanel → MySQL Databases (ver DEPLOY.md).
import fs from "node:fs";
import crypto from "node:crypto";

const dir = process.argv[2] ?? "entornos";
fs.mkdirSync(dir, { recursive: true });
const alfabeto = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789"; // sin caracteres ambiguos ni símbolos que rompan el .env
const clave = (n = 24) => Array.from(crypto.randomBytes(n), (b) => alfabeto[b % alfabeto.length]).join("");

const entornos = [
  { id: "dev", dominio: "prestamosdev.empresascloud.com", base: "cloudbus_prestamosdev", usuario: "cloudbus_pcdev" },
  { id: "qa", dominio: "prestamosqa.empresascloud.com", base: "cloudbus_prestamosqa", usuario: "cloudbus_pcqa" },
];
for (const e of entornos) {
  const destino = `${dir}/${e.id}.env`;
  if (fs.existsSync(destino)) { console.log(`${e.id}: ya existe, no se sobrescribe`); continue; }
  const contenido = [
    `# PrestaCloud · ${e.id} · generado ${new Date().toISOString().slice(0, 10)} · NO SUBIR A GIT`,
    `APP_ENV=${e.id}`,
    `APP_DEBUG=false`,
    `APP_URL=https://${e.dominio}`,
    `TZ_APP=America/Lima`,
    ``,
    `DB_HOST=localhost`,
    `DB_NAME=${e.base}`,
    `DB_USER=${e.usuario}`,
    `DB_PASS=${clave()}`,
    `DB_CHARSET=utf8mb4`,
    ``,
  ].join("\n");
  fs.writeFileSync(destino, contenido, { mode: 0o600 });
  console.log(`${e.id}: creado → base ${e.base} · usuario ${e.usuario} (${e.usuario.length} caracteres, el máximo de MySQL 5.7 es 16)`);
}

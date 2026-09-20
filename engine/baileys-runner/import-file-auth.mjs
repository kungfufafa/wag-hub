import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';
import { createPool } from 'mysql2/promise';
import { createSqlAuthStore } from './sql-auth.mjs';
import { validateSessionId } from './send-journal.mjs';

// Run with the runner stopped. Source files are intentionally never removed.
const [id, ...extra] = process.argv.slice(2);
if (!id || extra.length) throw new Error('Usage: npm run import-file-auth -- <session-id>');
validateSessionId(id);
const root = process.env.WAG_BAILEYS_SESSION_ROOT || path.join(path.dirname(fileURLToPath(import.meta.url)), 'sessions');
const directory = path.join(root, id);
if (!fs.lstatSync(directory).isDirectory() || fs.lstatSync(directory).isSymbolicLink()) throw new Error('Session source must be a regular directory.');
const values = {};
for (const file of fs.readdirSync(directory)) {
    if (!file.endsWith('.json')) continue;
    if (!/^[A-Za-z0-9_.@+-]{1,240}\.json$/.test(file)) throw new Error('Unsafe authentication filename.');
    const source = path.join(directory, file);
    const stat = fs.lstatSync(source);
    if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 16 * 1024 * 1024) throw new Error('Invalid authentication file.');
    values[file.slice(0, -5)] = JSON.parse(fs.readFileSync(source, 'utf8'), BufferJSON.reviver);
}
const pool = createPool({ host: process.env.WAG_BAILEYS_DB_HOST || '127.0.0.1', port: Number(process.env.WAG_BAILEYS_DB_PORT || 3306),
    database: process.env.WAG_BAILEYS_DB_DATABASE, user: process.env.WAG_BAILEYS_DB_USERNAME, password: process.env.WAG_BAILEYS_DB_PASSWORD,
    connectionLimit: 1, ...(process.env.WAG_BAILEYS_DB_TLS === 'true' ? { ssl: { rejectUnauthorized: true } } : {}) });
try {
    const store = await createSqlAuthStore({ pool, key: process.env.WAG_BAILEYS_AUTH_KEY, initAuthCreds, BufferJSON, proto });
    const result = await store.importSession(id, values);
    process.stdout.write(`Imported ${result.imported} encrypted authentication records for ${id}. Source files retained; no session was started.\n`);
} finally { await pool.end(); }

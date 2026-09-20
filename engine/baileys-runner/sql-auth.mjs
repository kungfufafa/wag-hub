import path from 'node:path';
import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { validateSessionId } from './send-journal.mjs';

export function createAuthCipher(key, codec = JSON) {
    const secret = Buffer.from(key || '', 'base64');
    if (secret.length !== 32) throw new Error('WAG_BAILEYS_AUTH_KEY must contain exactly 32 base64-encoded random bytes.');
    return {
        encrypt(value, context) {
            const nonce = randomBytes(12);
            const cipher = createCipheriv('aes-256-gcm', secret, nonce);
            cipher.setAAD(Buffer.from(context));
            const bytes = Buffer.from(JSON.stringify(value, codec.replacer));
            const encrypted = Buffer.concat([cipher.update(bytes), cipher.final()]);
            return Buffer.concat([nonce, cipher.getAuthTag(), encrypted]).toString('base64');
        },
        decrypt(value, context) {
            const bytes = Buffer.from(value, 'base64');
            const decipher = createDecipheriv('aes-256-gcm', secret, bytes.subarray(0, 12));
            decipher.setAAD(Buffer.from(context));
            decipher.setAuthTag(bytes.subarray(12, 28));
            return JSON.parse(Buffer.concat([decipher.update(bytes.subarray(28)), decipher.final()]).toString(), codec.reviver);
        },
    };
}

// A private runner table; consumer apps never access these records. All key sets
// are committed atomically, and authenticated encryption binds rows to owners.
export async function createSqlAuthStore({ pool, key, initAuthCreds, BufferJSON, proto }) {
    const cipher = createAuthCipher(key, BufferJSON);
    await pool.execute(`CREATE TABLE IF NOT EXISTS wag_runner_auth (
        session_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        key_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        ciphertext LONGTEXT NOT NULL,
        PRIMARY KEY (session_id, key_name)
    ) ENGINE=InnoDB`);
    const epochs = new Map();
    const writes = new Map();
    function serialize(id, operation) {
        const pending = (writes.get(id) || Promise.resolve()).catch(() => {}).then(operation);
        writes.set(id, pending);
        return pending;
    }
    const legacyKey = (name) => `legacy:${name.replace(/\//g, '__').replace(/:/g, '-')}`;
    async function load(id, name) {
        const [rows] = await pool.execute('SELECT ciphertext FROM wag_runner_auth WHERE session_id = ? AND key_name = ?', [id, name]);
        return rows.length ? cipher.decrypt(rows[0].ciphertext, `${id}:${name}`) : null;
    }
    async function write(id, values) {
        const connection = await pool.getConnection();
        try {
            await connection.beginTransaction();
            for (const [name, value] of Object.entries(values)) {
                if (value == null) await connection.execute('DELETE FROM wag_runner_auth WHERE session_id = ? AND key_name = ?', [id, name]);
                else await connection.execute('INSERT INTO wag_runner_auth (session_id,key_name,ciphertext) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext)',
                    [id, name, cipher.encrypt(value, `${id}:${name}`)]);
            }
            await connection.commit();
        } catch (error) { await connection.rollback(); throw error; }
        finally { connection.release(); }
    }
    return {
        async useAuthState(directory) {
            const id = validateSessionId(path.basename(directory));
            const epoch = (epochs.get(id) || 0) + 1;
            epochs.set(id, epoch);
            await writes.get(id)?.catch(() => {});
            const creds = await load(id, 'creds') || initAuthCreds();
            const guardedWrite = (values) => serialize(id, () => {
                if (epochs.get(id) !== epoch) throw new Error('Authentication state was superseded.');
                return write(id, values);
            });
            return {
                state: { creds, keys: {
                    async get(type, ids) {
                        const values = {};
                        for (const name of ids) {
                            let value = await load(id, `${type}-${name}`);
                            if (value == null) value = await load(id, legacyKey(`${type}-${name}`));
                            if (type === 'app-state-sync-key' && value && proto?.Message?.AppStateSyncKeyData) value = proto.Message.AppStateSyncKeyData.fromObject(value);
                            values[name] = value;
                        }
                        return values;
                    },
                    async set(data) {
                        const values = {};
                        for (const [type, entries] of Object.entries(data)) for (const [name, value] of Object.entries(entries)) {
                            values[`${type}-${name}`] = value;
                            values[legacyKey(`${type}-${name}`)] = null;
                        }
                        await guardedWrite(values);
                    },
                } },
                saveCreds: () => guardedWrite({ creds }),
            };
        },
        async importSession(id, values) {
            validateSessionId(id);
            if (!values.creds || typeof values.creds !== 'object') throw new Error('Missing valid creds.json.');
            const connection = await pool.getConnection();
            try {
                await connection.beginTransaction();
                const [existing] = await connection.execute('SELECT session_id FROM wag_runner_auth WHERE session_id = ? FOR UPDATE', [id]);
                if (existing.length) throw new Error('SQL authentication already exists; refusing to overwrite.');
                for (const [name, value] of Object.entries(values)) {
                    if (!/^[A-Za-z0-9_.@:+-]{1,240}$/.test(name)) throw new Error('Invalid authentication key filename.');
                    const storedName = name === 'creds' ? name : `legacy:${name}`;
                    await connection.execute('INSERT INTO wag_runner_auth (session_id,key_name,ciphertext) VALUES (?,?,?)',
                        [id, storedName, cipher.encrypt(value, `${id}:${storedName}`)]);
                }
                await connection.commit();
                return { imported: Object.keys(values).length };
            } catch (error) { await connection.rollback(); throw error; }
            finally { connection.release(); }
        },
        async clear(id) {
            validateSessionId(id);
            epochs.set(id, (epochs.get(id) || 0) + 1);
            await serialize(id, () => pool.execute('DELETE FROM wag_runner_auth WHERE session_id = ?', [id]));
        },
        async ready() { await pool.query('SELECT 1'); return true; },
        async close() { await pool.end(); },
    };
}

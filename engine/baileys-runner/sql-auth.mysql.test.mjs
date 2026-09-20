import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { createSqlAuthStore } from './sql-auth.mjs';

// Creates/drops only its own fresh database. Use a disposable MySQL instance.
test('real MySQL encrypted auth, atomic rollback, legacy import and logout fencing', {
    skip: !process.env.WAG_BAILEYS_TEST_MYSQL_SOCKET,
}, async () => {
    const { default: mysql } = await import('mysql2/promise');
    const { BufferJSON, initAuthCreds, proto } = await import('@whiskeysockets/baileys');
    const socketPath = process.env.WAG_BAILEYS_TEST_MYSQL_SOCKET;
    const user = process.env.WAG_BAILEYS_TEST_MYSQL_USER || 'root';
    const admin = await mysql.createConnection({ socketPath, user });
    const database = `wag_runner_auth_test_${randomBytes(6).toString('hex')}`;
    let created = false;
    let store;
    try {
        await admin.query(`CREATE DATABASE ${database}`);
        created = true;
        const pool = mysql.createPool({ socketPath, user, database, connectionLimit: 2 });
        store = await createSqlAuthStore({ pool, key: randomBytes(32).toString('base64'), BufferJSON, initAuthCreds, proto });
        const auth = await store.useAuthState('/test/sql-session');
        auth.state.creds.registered = true;
        auth.state.creds.testMarker = 'sensitive-auth-value';
        await auth.saveCreds();
        await auth.state.keys.set({ session: { alice: { key: Buffer.from([1, 2, 3]), stage: 'original' } } });
        const restored = await store.useAuthState('/test/sql-session');
        assert.equal(restored.state.creds.registered, true);
        assert.equal(restored.state.creds.testMarker, 'sensitive-auth-value');
        assert.ok(Buffer.isBuffer(restored.state.creds.noiseKey.private));
        assert.deepEqual((await restored.state.keys.get('session', ['alice'])).alice.key, Buffer.from([1, 2, 3]));
        const [rows] = await pool.query('SELECT ciphertext FROM wag_runner_auth');
        assert.ok(rows.every((row) => !row.ciphertext.includes('sensitive-auth-value')));
        await assert.rejects(restored.state.keys.set({ session: { alice: { stage: 'must-rollback' }, ['b'.repeat(300)]: { stage: 'too-long-key' } } }));
        assert.equal((await restored.state.keys.get('session', ['alice'])).alice.stage, 'original');
        await store.importSession('import-session', { creds: { registered: true }, 'session-62812-7.0': { key: Buffer.from([4, 5, 6]) } });
        const imported = await store.useAuthState('/test/import-session');
        assert.deepEqual((await imported.state.keys.get('session', ['62812:7.0']))['62812:7.0'].key, Buffer.from([4, 5, 6]));
        await assert.rejects(store.importSession('import-session', { creds: { registered: false } }), /refusing to overwrite/);
        await store.clear('import-session');
        await assert.rejects(imported.saveCreds(), /superseded/);
        const [remaining] = await pool.query('SELECT COUNT(*) AS count FROM wag_runner_auth WHERE session_id=?', ['import-session']);
        assert.equal(remaining[0].count, 0);
    } finally {
        if (store) await store.close();
        if (created) await admin.query(`DROP DATABASE ${database}`);
        await admin.end();
    }
});

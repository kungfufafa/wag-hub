import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { createAuthCipher, createSqlAuthStore } from './sql-auth.mjs';

const key = randomBytes(32).toString('base64');
function fakePool() {
    const rows = new Map();
    let fault;
    async function execute(sql, parameters = []) {
        const [id, name, ciphertext] = parameters;
        const rowKey = `${id}:${name}`;
        if (sql.startsWith('CREATE')) return [[], []];
        if (sql.startsWith('SELECT session_id')) return [[...rows.keys()].filter((stored) => stored.startsWith(`${id}:`)).map(() => ({ session_id: id }))];
        if (sql.startsWith('SELECT ciphertext')) return [[...(rows.has(rowKey) ? [{ ciphertext: rows.get(rowKey) }] : [])]];
        if (sql.startsWith('INSERT')) {
            if (name === fault) throw new Error('simulated database error');
            rows.set(rowKey, ciphertext);
            return [[], []];
        }
        if (sql.startsWith('DELETE')) {
            if (parameters.length === 2) rows.delete(rowKey);
            else for (const storedKey of rows.keys()) if (storedKey.startsWith(`${id}:`)) rows.delete(storedKey);
            return [[], []];
        }
        throw new Error(`Unexpected SQL: ${sql}`);
    }
    return { rows, execute, query: execute, end: async () => {}, failOn: (name) => { fault = name; },
        async getConnection() {
            let before;
            return { execute, async beginTransaction() { before = new Map(rows); }, async commit() {},
                async rollback() { rows.clear(); for (const [name, value] of before) rows.set(name, value); }, release() {} };
        } };
}

test('auth cipher randomizes encryption and binds ciphertext to the session and key', () => {
    const cipher = createAuthCipher(key);
    const first = cipher.encrypt({ secret: 'private-signal-key' }, 'session-a:creds');
    const second = cipher.encrypt({ secret: 'private-signal-key' }, 'session-a:creds');
    assert.notEqual(first, second);
    assert.equal(Buffer.from(first, 'base64').includes(Buffer.from('private-signal-key')), false);
    assert.deepEqual(cipher.decrypt(first, 'session-a:creds'), { secret: 'private-signal-key' });
    assert.throws(() => cipher.decrypt(first, 'session-b:creds'));
    const corrupt = Buffer.from(first, 'base64');
    corrupt[corrupt.length - 1] ^= 1;
    assert.throws(() => cipher.decrypt(corrupt.toString('base64'), 'session-a:creds'));
    assert.throws(() => createAuthCipher('short'));
});

test('SQL auth restores credentials and atomically rolls back a failed signal-key set', async () => {
    const pool = fakePool();
    const store = await createSqlAuthStore({ pool, key, initAuthCreds: () => ({ registered: false, secret: 'private-secret' }) });
    const first = await store.useAuthState('/sessions/session-a');
    first.state.creds.registered = true;
    await first.saveCreds();
    await first.state.keys.set({ session: { alice: { ratchet: 'one' }, bob: { ratchet: 'two' } } });
    const persisted = await store.useAuthState('/sessions/session-a');
    assert.equal(persisted.state.creds.registered, true);
    assert.deepEqual(await persisted.state.keys.get('session', ['alice']), { alice: { ratchet: 'one' } });
    assert.equal([...pool.rows.values()].some((ciphertext) => ciphertext.includes('private-secret')), false);
    pool.failOn('session-bob');
    await assert.rejects(persisted.state.keys.set({ session: { alice: { ratchet: 'changed' }, bob: { ratchet: 'changed' } } }));
    assert.deepEqual(await persisted.state.keys.get('session', ['alice']), { alice: { ratchet: 'one' } });
});

test('logout invalidates old auth writers and removes all keys without affecting other sessions', async () => {
    const pool = fakePool();
    const store = await createSqlAuthStore({ pool, key, initAuthCreds: () => ({ registered: false }) });
    const first = await store.useAuthState('/sessions/session-a');
    const other = await store.useAuthState('/sessions/session-b');
    await first.saveCreds();
    await other.saveCreds();
    await first.state.keys.set({ session: { alice: { key: 'secret' } } });
    await store.clear('session-a');
    await assert.rejects(first.saveCreds(), /superseded/);
    await assert.rejects(first.state.keys.set({ session: { alice: { key: 'late' } } }), /superseded/);
    assert.deepEqual([...pool.rows.keys()], ['session-b:creds']);
});


test('legacy file-auth import preserves encoded key lookup, refuses overwrite, and cannot resurrect deleted keys', async () => {
    const pool = fakePool();
    const store = await createSqlAuthStore({ pool, key, initAuthCreds: () => ({ registered: false }) });
    await store.importSession('session-a', { creds: { registered: true }, 'session-628123-7.0': { privateKey: 'old-secret' } });
    const auth = await store.useAuthState('/sessions/session-a');
    assert.equal(auth.state.creds.registered, true);
    assert.deepEqual(await auth.state.keys.get('session', ['628123:7.0']), { '628123:7.0': { privateKey: 'old-secret' } });
    await assert.rejects(store.importSession('session-a', { creds: { registered: false } }), /refusing to overwrite/);
    await auth.state.keys.set({ session: { '628123:7.0': null } });
    assert.deepEqual(await auth.state.keys.get('session', ['628123:7.0']), { '628123:7.0': null });
});

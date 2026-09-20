import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { EventEmitter } from 'node:events';
import { Readable } from 'node:stream';
import { createHash } from 'node:crypto';
import { createWhatsAppEngine } from './engine.mjs';
import { createRequestHandler } from './http-server.mjs';
import { createSendJournal, messageFingerprint } from './send-journal.mjs';
import { createEventSpool } from './durable-store.mjs';

const reasons = { loggedOut: 401, badSession: 500, multideviceMismatch: 411, forbidden: 403, connectionReplaced: 440, restartRequired: 515 };
const nextTurn = () => new Promise((resolve) => setImmediate(resolve));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const payload = { phone: '081234567890', text: 'Jadwal wawancara', idempotency_key: 'delivery-1' };

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((accept, fail) => { resolve = accept; reject = fail; });

    return { promise, resolve, reject };
}

function fixture(t, overrides = {}) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'waghub-wa-test-'));
    const sockets = [];
    const socketOptions = [];
    const sent = [];
    const auth = { state: { creds: { registered: false }, keys: {} }, saveCreds() {} };
    const options = {
        sessionRoot: path.join(root, 'sessions'),
        journalRoot: path.join(root, 'journal'),
        useAuthState: async () => auth,
        fetchVersion: async () => ({ version: [2, 3000, 1] }),
        qrToDataURL: async (qr) => `data:${qr}`,
        disconnectReasons: reasons,
        reconnectDelay: () => 10,
        versionTimeoutMs: 50,
        pairingTimeoutMs: 50,
        logoutTimeoutMs: 50,
        sendTimeoutMs: 100,
        makeSocket: (configuration) => {
            const calls = [];
            const sock = {
                ev: new EventEmitter(),
                user: { id: '6281234567890:7@s.whatsapp.net' },
                calls,
                end() { calls.push('end'); },
                ws: { close() { calls.push('close'); } },
                async logout() { calls.push('logout'); },
                async requestPairingCode(phone) { calls.push(`pair:${phone}`); return 'ABCDEFGH'; },
                async sendMessage(jid, content, messageOptions) {
                    sent.push({ jid, content, options: messageOptions });

                    return { key: { id: messageOptions.messageId } };
                },
            };
            sockets.push(sock);
            socketOptions.push(configuration);

            return sock;
        },
        ...overrides,
    };
    const engine = createWhatsAppEngine(options);
    t.after(async () => {
        await engine.shutdown();
        fs.rmSync(root, { recursive: true, force: true });
    });

    return { engine, root, sockets, socketOptions, sent, options, auth };
}

async function connect(f, id = 'rekrutmen-1') {
    await f.engine.startSession(id, { mode: 'qr' });
    const sock = f.sockets.at(-1);
    sock.ev.emit('connection.update', { connection: 'open' });

    return sock;
}

async function request(engine, method, url, body) {
    const incoming = Readable.from(body === undefined ? [] : [Buffer.from(typeof body === 'string' ? body : JSON.stringify(body))]);
    incoming.method = method;
    incoming.url = url;
    const outgoing = {
        writeHead(code) { this.code = code; },
        end(content) { this.body = JSON.parse(content); },
    };
    incoming.headers = { authorization: 'Bearer test-token' };
    await createRequestHandler(engine, { token: 'test-token' })(incoming, outgoing);

    return outgoing;
}

test('session IDs and explicit pairing input are validated before filesystem access', async (t) => {
    const f = fixture(t);

    for (const id of ['.', '..', 'HR-1', 'hr--1', 'hr-', 'a'.repeat(48), 'rekrutmen-1/..']) {
        assert.throws(() => f.engine.startSession(id, { mode: 'qr' }), { errorCode: 'invalid_session' });
        assert.throws(() => f.engine.stopSession(id), { errorCode: 'invalid_session' });
    }

    assert.throws(() => f.engine.startSession('rekrutmen-1'), { errorCode: 'invalid_mode' });
    assert.throws(() => f.engine.startSession('rekrutmen-1', { mode: 'pairing', phone: 'abc0812345678' }), { errorCode: 'invalid_phone' });
    assert.equal(fs.existsSync(f.options.sessionRoot), false);
});

test('pairing waits for QR readiness and mode or phone changes replace the old socket', async (t) => {
    const f = fixture(t);
    await f.engine.startSession('rekrutmen-1', { mode: 'pairing', phone: '081234567890' });
    const first = f.sockets[0];
    assert.deepEqual(first.calls, []);
    first.ev.emit('connection.update', { qr: 'pair-ready' });
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').pairing_code, 'ABCD-EFGH');
    assert.deepEqual(first.calls, ['pair:6281234567890']);

    await f.engine.startSession('rekrutmen-1', { mode: 'pairing', phone: '081234567891' });
    assert.equal(f.sockets.length, 2);
    assert.ok(first.calls.includes('end'));
    assert.equal(f.engine.session('rekrutmen-1').pairing_code, null);
    assert.equal(f.engine.session('rekrutmen-1').phone, '6281234567891');

    await f.engine.startSession('rekrutmen-1', { mode: 'qr', phone: '081234567891' });
    f.sockets[2].ev.emit('connection.update', { qr: 'new-qr' });
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').status, 'qr');
    assert.equal(f.engine.session('rekrutmen-1').qr, 'data:new-qr');
    assert.equal(f.engine.session('rekrutmen-1').pairing_code, null);
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    assert.equal(f.sockets.length, 3);
});

test('concurrent starts for the same session produce one socket', async (t) => {
    const f = fixture(t);
    await Promise.all(Array.from({ length: 5 }, () => f.engine.startSession('rekrutmen-1', { mode: 'qr' })));
    assert.equal(f.sockets.length, 1);
});

test('stop cancels a start waiting for auth without creating an orphan socket', async (t) => {
    const auth = deferred();
    const entered = deferred();
    const f = fixture(t, { useAuthState: () => { entered.resolve(); return auth.promise; } });
    const starting = f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    const rejected = assert.rejects(starting, { errorCode: 'session_cancelled' });
    await entered.promise;
    const stopping = f.engine.stopSession('rekrutmen-1');
    auth.resolve(f.auth);
    await Promise.all([rejected, stopping]);
    assert.equal(f.sockets.length, 0);
    assert.equal(f.engine.health().sessions, 0);
    assert.equal(fs.existsSync(path.join(f.options.sessionRoot, 'rekrutmen-1')), false);
});

test('stop cancels a start waiting for version and a later start still works', async (t) => {
    const version = deferred();
    const entered = deferred();
    const f = fixture(t, { fetchVersion: () => { entered.resolve(); return version.promise; } });
    const starting = f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    const rejected = assert.rejects(starting, { errorCode: 'session_cancelled' });
    await entered.promise;
    const stopping = f.engine.stopSession('rekrutmen-1');
    version.resolve({ version: [2, 3000, 1] });
    await Promise.all([rejected, stopping]);
    assert.equal(f.sockets.length, 0);
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    assert.equal(f.sockets.length, 1);
});

test('delayed QR cannot overwrite open, replaced socket, or a newer QR', async (t) => {
    const renders = new Map();
    const f = fixture(t, { qrToDataURL: (qr) => { const pending = deferred(); renders.set(qr, pending); return pending.promise; } });
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    const sock = f.sockets[0];
    sock.ev.emit('connection.update', { qr: 'first' });
    sock.ev.emit('connection.update', { qr: 'second' });
    await nextTurn();
    renders.get('second').resolve('data:second');
    await nextTurn();
    renders.get('first').resolve('data:first');
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').qr, 'data:second');

    sock.ev.emit('connection.update', { qr: 'late' });
    await nextTurn();
    sock.ev.emit('connection.update', { connection: 'open' });
    renders.get('late').resolve('data:late');
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').status, 'connected');
    assert.equal(f.engine.session('rekrutmen-1').qr, null);

    await f.engine.stopSession('rekrutmen-1', false);
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    f.sockets[1].ev.emit('connection.update', { qr: 'replaced' });
    await nextTurn();
    await f.engine.startSession('rekrutmen-1', { mode: 'pairing', phone: '081234567891' });
    renders.get('replaced').resolve('data:replaced');
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').status, 'pairing');
    assert.equal(f.engine.session('rekrutmen-1').qr, null);
});

test('late pairing completion cannot overwrite connected state', async (t) => {
    const f = fixture(t);
    const code = deferred();
    await f.engine.startSession('rekrutmen-1', { mode: 'pairing', phone: '081234567890' });
    f.sockets[0].requestPairingCode = () => code.promise;
    f.sockets[0].ev.emit('connection.update', { qr: 'ready' });
    await nextTurn();
    f.sockets[0].ev.emit('connection.update', { connection: 'open' });
    code.resolve('ABCDEFGH');
    await nextTurn();
    assert.equal(f.engine.session('rekrutmen-1').status, 'connected');
    assert.equal(f.engine.session('rekrutmen-1').pairing_code, null);
});

test('connection close clears stale QR and stop cancels scheduled reconnect', async (t) => {
    const f = fixture(t);
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    const sock = f.sockets[0];
    sock.ev.emit('connection.update', { qr: 'old' });
    await nextTurn();
    sock.ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { statusCode: 408 } } });
    assert.equal(f.engine.session('rekrutmen-1').qr, null);
    assert.equal(f.engine.session('rekrutmen-1').status, 'connecting');
    await f.engine.stopSession('rekrutmen-1');
    await pause(25);
    assert.equal(f.sockets.length, 1);
});

test('reconnect limit stops automatic attempts and explicit connect resets the limit', async (t) => {
    const f = fixture(t, { maxReconnect: 1 });
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    f.sockets[0].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { statusCode: 408 } } });
    await pause(25);
    assert.equal(f.sockets.length, 2);
    f.sockets[1].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { statusCode: 408 } } });
    assert.equal(f.engine.session('rekrutmen-1').status, 'disconnected');
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    assert.equal(f.engine.session('rekrutmen-1').reconnect_attempt, 0);
});

test('logout calls device logout, flushes auth writes, and preserves the message journal', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const result = await f.engine.sendText('rekrutmen-1', payload);
    const credsFile = path.join(f.options.sessionRoot, 'rekrutmen-1', 'creds.json');
    f.auth.saveCreds = async () => { await pause(5); fs.writeFileSync(credsFile, '{}'); };
    await f.engine.stopSession('rekrutmen-1', false);
    await connect(f);
    f.sockets[1].ev.emit('creds.update', {});
    const stopped = await f.engine.stopSession('rekrutmen-1', true);
    assert.equal(stopped.logout_confirmed, true);
    assert.ok(f.sockets[1].calls.indexOf('logout') < f.sockets[1].calls.indexOf('end'));
    assert.equal(sock.calls.includes('logout'), false);
    assert.equal(fs.existsSync(credsFile), false);
    assert.deepEqual(f.engine.messageStatus('rekrutmen-1', payload.idempotency_key), result);
});

test('logout timeout removes local auth but honestly reports unconfirmed phone unlink', async (t) => {
    const f = fixture(t, { logoutTimeoutMs: 10 });
    const sock = await connect(f);
    sock.logout = () => new Promise(() => {});
    const stopped = await f.engine.stopSession('rekrutmen-1', true);
    assert.equal(stopped.ok, true);
    assert.equal(stopped.logout_confirmed, false);
    assert.match(stopped.message, /Perangkat tertaut/);
    assert.equal(fs.existsSync(path.join(f.options.sessionRoot, 'rekrutmen-1')), false);
    assert.ok(sock.calls.includes('end'));
});

test('ordinary shutdown preserves saved credentials and does not log out the device', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const credsFile = path.join(f.options.sessionRoot, 'rekrutmen-1', 'creds.json');
    fs.writeFileSync(credsFile, JSON.stringify({ registered: true }));
    await f.engine.shutdown();
    assert.equal(fs.existsSync(credsFile), true);
    assert.equal(sock.calls.includes('logout'), false);
    assert.ok(sock.calls.includes('end'));
});

test('version lookup is shared and times out to default options without an undefined override', async (t) => {
    let fetches = 0;
    let receivedOptions;
    const f = fixture(t, {
        versionTimeoutMs: 10,
        fetchVersion: (options) => { fetches += 1; receivedOptions = options; return new Promise(() => {}); },
    });
    await Promise.all([
        f.engine.startSession('rekrutmen-1', { mode: 'qr' }),
        f.engine.startSession('rekrutmen-2', { mode: 'qr' }),
    ]);
    assert.equal(fetches, 1);
    assert.deepEqual(receivedOptions, { timeout: 10 });
    assert.equal(f.socketOptions.length, 2);
    assert.equal(Object.hasOwn(f.socketOptions[0], 'version'), false);
});

test('restoration requires explicit persisted RUNNING intent and pause survives restart', async (t) => {
    const f = fixture(t);
    f.auth.state.creds.registered = true;
    const credsFile = path.join(f.options.sessionRoot, 'rekrutmen-1', 'creds.json');
    fs.mkdirSync(path.dirname(credsFile), { recursive: true });
    fs.writeFileSync(credsFile, '{}');
    await f.engine.restoreSessions();
    assert.equal(f.sockets.length, 0, 'legacy credentials alone are not running intent');
    await connect(f);
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    await recovered.restoreSessions();
    assert.equal(f.sockets.length, 2);
    await recovered.stopSession('rekrutmen-1', false);
    await recovered.shutdown();
    const paused = createWhatsAppEngine(f.options);
    await paused.restoreSessions();
    assert.equal(f.sockets.length, 2);
    assert.equal(paused.session('rekrutmen-1').desired_state, 'STOPPED');
    assert.equal((await paused.sendText('rekrutmen-1', payload)).status, 'failed');
    assert.equal(f.sockets.length, 2, 'send must never resurrect a paused account');
    await paused.shutdown();
});

test('restoration supports UUID session IDs with durable intent', async (t) => {
    const f = fixture(t);
    f.auth.state.creds.registered = true;
    const id = '11223344-5566-7788-9900-aabbccddeeff';
    await f.engine.startSession(id, { mode: 'qr', generation: 5 });
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    await recovered.restoreSessions();
    assert.equal(f.sockets.length, 2);
    assert.equal(recovered.session(id).generation, 5);
    assert.equal(recovered.session(id).canonical_status, 'STARTING');
    await recovered.shutdown();
});

test('number lookup uses the selected socket and distinguishes negative from unknown results', async (t) => {
    const f = fixture(t);
    const sock = await connect(f, 'support-1');
    for (const [answer, expected] of [[[{ exists: true }], true], [[], false], [[{ exists: false }], false], [undefined, null], [[{}], null]]) {
        sock.onWhatsApp = async (phone) => {
            assert.equal(phone, '6281234567890');
            return answer;
        };
        const response = await request(f.engine, 'POST', '/sessions/support-1/numbers/check', { phone: '081234567890' });
        assert.equal(response.code, 200);
        assert.equal(response.body.registered, expected);
    }
    const invalid = await request(f.engine, 'POST', '/sessions/support-1/numbers/check', { phone: '123' });
    assert.equal(invalid.code, 422);
    const disconnected = await request(f.engine, 'POST', '/sessions/support-2/numbers/check', { phone: '081234567890' });
    assert.equal(disconnected.code, 409);
    assert.equal(disconnected.body.error_code, 'not_connected');
    assert.equal(f.sent.length, 0);
});

test('starting fresh pairing or QR clears an unfinished pairing identity before opening the socket', async (t) => {
    let reads = 0;
    const incomplete = { state: { creds: { registered: false, me: { id: '6281234567890@s.whatsapp.net' } }, keys: {} }, saveCreds() {} };
    const fresh = { state: { creds: { registered: false }, keys: {} }, saveCreds() {} };
    const f = fixture(t, { useAuthState: async () => { reads += 1; return reads === 1 ? incomplete : fresh; } });
    await f.engine.startSession('rekrutmen-1', { mode: 'qr' });
    assert.equal(reads, 2);
    assert.equal(f.socketOptions[0].auth.creds.me, undefined);
    assert.equal(f.sockets.length, 1);
});

test('a definite pre-send failure does not claim the key and can retry after connecting', async (t) => {
    const f = fixture(t);
    const failed = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(failed.status, 'failed');
    assert.equal(failed.retryable, true);
    assert.throws(() => f.engine.messageStatus('rekrutmen-1', payload.idempotency_key), { statusCode: 404 });
    await connect(f);
    const sent = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(sent.status, 'sent');
    assert.equal(f.sent.length, 1);
});

test('unavailable send returns immediately while saved session restore remains pending', async (t) => {
    const auth = deferred();
    const f = fixture(t, { useAuthState: () => auth.promise });
    const directory = path.join(f.options.sessionRoot, 'rekrutmen-1');
    fs.mkdirSync(directory, { recursive: true });
    fs.writeFileSync(path.join(directory, 'creds.json'), '{}');
    const result = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(result.status, 'failed');
    assert.equal(result.retryable, true);
    assert.equal(f.sockets.length, 0);
    auth.resolve(f.auth);
    await nextTurn();
});

test('concurrent and later duplicate sends use one stable message ID and one socket send', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const delivered = deferred();
    let calls = 0;
    let messageId;
    sock.sendMessage = async (_jid, _content, options) => {
        calls += 1;
        messageId = options.messageId;
        await delivered.promise;

        return { key: { id: messageId } };
    };
    const first = f.engine.sendText('rekrutmen-1', payload);
    const duplicate = f.engine.sendText('rekrutmen-1', { ...payload, phone: '+62 81234567890' });
    await nextTurn();
    assert.equal(calls, 1);
    delivered.resolve();
    const results = await Promise.all([first, duplicate]);
    assert.deepEqual(results[0], results[1]);
    assert.equal(results[0].id, messageId);
    assert.deepEqual(await f.engine.sendText('rekrutmen-1', payload), results[0]);
    assert.equal(calls, 1);
});

test('same key with different content or recipient fails both during and after sending', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const delivered = deferred();
    sock.sendMessage = () => delivered.promise;
    const first = f.engine.sendText('rekrutmen-1', payload);
    assert.throws(() => f.engine.sendText('rekrutmen-1', { ...payload, text: 'Pesan berbeda' }), { errorCode: 'idempotency_conflict' });
    delivered.resolve();
    await first;
    await assert.rejects(f.engine.sendText('rekrutmen-1', { ...payload, phone: '081234567899' }), { errorCode: 'idempotency_conflict' });
});

test('send errors and timeouts become durable unknown and are never retried', async (t) => {
    const f = fixture(t, { sendTimeoutMs: 10 });
    const sock = await connect(f);
    let calls = 0;
    sock.sendMessage = async () => { calls += 1; throw new Error('Socket write was accepted but connection closed'); };
    const unknown = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(unknown.status, 'unknown');
    assert.equal(unknown.retryable, false);
    assert.deepEqual(await f.engine.sendText('rekrutmen-1', payload), unknown);
    assert.equal(calls, 1);

    const restarted = createWhatsAppEngine(f.options);
    assert.deepEqual(await restarted.sendText('rekrutmen-1', payload), unknown);
    assert.deepEqual(restarted.messageStatus('rekrutmen-1', payload.idempotency_key), unknown);
    await restarted.shutdown();
    sock.sendMessage = () => { calls += 1; return new Promise(() => {}); };
    const timeout = await f.engine.sendText('rekrutmen-1', { ...payload, idempotency_key: 'delivery-timeout' });
    assert.equal(timeout.status, 'unknown');
    assert.equal(calls, 2);
});

test('a pending journal recovered after a crash returns unknown without connecting or resending', async (t) => {
    const f = fixture(t);
    createSendJournal(f.options.journalRoot).write({
        version: 1,
        sessionId: 'rekrutmen-1',
        key: payload.idempotency_key,
        fingerprint: messageFingerprint('6281234567890', payload.text),
        messageId: '3EB0PERSISTED',
        status: 'pending',
    });
    const result = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(result.status, 'unknown');
    assert.equal(result.id, '3EB0PERSISTED');
    assert.equal(f.sockets.length, 0);
    assert.equal(f.sent.length, 0);
});

test('late send completion reconciles a timeout to sent without another socket send', async (t) => {
    const f = fixture(t, { sendTimeoutMs: 10 });
    const sock = await connect(f);
    const sent = deferred();
    let calls = 0;
    sock.sendMessage = () => { calls += 1; return sent.promise; };
    const initial = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(initial.status, 'unknown');
    assert.equal(f.engine.messageStatus('rekrutmen-1', payload.idempotency_key).status, 'unknown');
    sent.resolve();
    await nextTurn();
    const confirmed = f.engine.messageStatus('rekrutmen-1', payload.idempotency_key);
    assert.equal(confirmed.status, 'sent');
    assert.equal(confirmed.id, initial.id);
    assert.equal(confirmed.error_code, undefined);
    assert.equal(confirmed.message, undefined);
    assert.deepEqual(await f.engine.sendText('rekrutmen-1', payload), confirmed);
    assert.equal(calls, 1);
});

test('shutdown leaves an in-flight journal protected from resending during recovery', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const completion = deferred();
    let calls = 0;
    sock.sendMessage = () => { calls += 1; return completion.promise; };
    const sending = f.engine.sendText('rekrutmen-1', payload);
    await nextTurn();
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    const unknown = await recovered.sendText('rekrutmen-1', payload);
    assert.equal(unknown.status, 'unknown');
    assert.equal(calls, 1);
    completion.resolve();
    const confirmed = await sending;
    assert.equal(confirmed.status, 'sent');
    assert.equal(recovered.messageStatus('rekrutmen-1', payload.idempotency_key).status, 'sent');
    assert.equal(calls, 1);
    await recovered.shutdown();
});

test('failure persisting send outcome retains pending protection and returns unknown', async (t) => {
    const f = fixture(t);
    const realJournal = createSendJournal(f.options.journalRoot);
    let writes = 0;
    const engine = createWhatsAppEngine({
        ...f.options,
        journal: {
            read: realJournal.read,
            write(entry) { writes += 1; if (writes > 1) { throw new Error('Disk full'); } realJournal.write(entry); },
        },
    });
    await engine.startSession('rekrutmen-1', { mode: 'qr' });
    f.sockets[0].ev.emit('connection.update', { connection: 'open' });
    const result = await engine.sendText('rekrutmen-1', payload);
    assert.equal(result.status, 'unknown');
    assert.equal(f.sent.length, 1);
    assert.equal(realJournal.read('rekrutmen-1', payload.idempotency_key).status, 'pending');
    assert.equal((await f.engine.sendText('rekrutmen-1', payload)).status, 'unknown');
    assert.equal(f.sent.length, 1);
    await engine.shutdown();
});

test('corrupt journal fails closed and never resends', async (t) => {
    const f = fixture(t);
    await connect(f);
    const directory = path.join(f.options.journalRoot, 'rekrutmen-1');
    fs.mkdirSync(directory, { recursive: true });
    const digest = createHash('sha256').update(payload.idempotency_key).digest('hex');
    fs.writeFileSync(path.join(directory, `${digest}.json`), '{broken');
    await assert.rejects(f.engine.sendText('rekrutmen-1', payload), { errorCode: 'journal_corrupt', retryable: false });
    assert.equal(f.sent.length, 0);
});

test('HTTP contract validates inputs and exposes sent, failed, conflict, unknown, and lookup', async (t) => {
    const f = fixture(t);
    assert.equal((await request(f.engine, 'POST', '/sessions', { id: 'rekrutmen-1' })).code, 422);
    assert.equal((await request(f.engine, 'POST', '/sessions', { id: '..', mode: 'qr' })).code, 422);
    assert.equal((await request(f.engine, 'POST', '/sessions', { id: 'rekrutmen-1', mode: 'restore' })).code, 422);
    assert.equal((await request(f.engine, 'POST', '/sessions', '{broken')).code, 400);
    const unavailable = await request(f.engine, 'POST', '/sessions/rekrutmen-1/send', payload);
    assert.equal(unavailable.code, 409);
    assert.equal(unavailable.body.retryable, true);
    assert.equal(unavailable.body.status, 'failed');
    const missing = await request(f.engine, 'GET', '/sessions/rekrutmen-1/messages/delivery-1');
    assert.equal(missing.code, 404);
    await connect(f);
    const sent = await request(f.engine, 'POST', '/sessions/rekrutmen-1/send', payload);
    assert.equal(sent.code, 200);
    assert.equal(sent.body.status, 'sent');
    const lookup = await request(f.engine, 'GET', '/sessions/rekrutmen-1/messages/delivery-1');
    assert.deepEqual(lookup.body, sent.body);
    const conflict = await request(f.engine, 'POST', '/sessions/rekrutmen-1/send', { ...payload, text: 'Different' });
    assert.equal(conflict.code, 409);
    assert.equal(conflict.body.status, 'failed');
    assert.equal(conflict.body.retryable, false);
    assert.equal(conflict.body.error_code, 'idempotency_conflict');
    f.sockets[0].sendMessage = async () => { throw new Error('Connection closed'); };
    const unknown = await request(f.engine, 'POST', '/sessions/rekrutmen-1/send', { ...payload, idempotency_key: 'delivery-2' });
    assert.equal(unknown.code, 200);
    assert.equal(unknown.body.status, 'unknown');
    assert.equal(unknown.body.retryable, false);
});

test('explicit generations fence old commands, callbacks, and queued sends', async (t) => {
    const f = fixture(t);
    await f.engine.startSession('support-1', { mode: 'qr', generation: 3 });
    const sock = f.sockets[0];
    sock.ev.emit('connection.update', { connection: 'open' });
    const stale = await f.engine.sendText('support-1', { ...payload, generation: 2 });
    assert.equal(stale.error_code, 'stale_generation');
    assert.equal(f.sent.length, 0);
    await f.engine.stopSession('support-1', false, { generation: 4 });
    assert.throws(() => f.engine.startSession('support-1', { mode: 'qr', generation: 3 }), { errorCode: 'stale_generation' });
    sock.ev.emit('connection.update', { connection: 'open' });
    assert.equal(f.engine.session('support-1').canonical_status, 'STOPPED');
    const recovered = createWhatsAppEngine(f.options);
    assert.throws(() => recovered.startSession('support-1', { mode: 'qr', generation: 3 }), { errorCode: 'stale_generation' });
    await recovered.shutdown();
});

test('authentication challenges expire and events never contain challenge secrets', async (t) => {
    const f = fixture(t, { qrTtlMs: 500 });
    await f.engine.startSession('support-1', { mode: 'qr' });
    f.sockets[0].ev.emit('connection.update', { qr: 'private-qr-secret' });
    await nextTurn();
    const snapshot = f.engine.session('support-1');
    assert.ok(snapshot.challenge_id);
    assert.ok(snapshot.expires_at);
    await pause(550);
    assert.equal(f.engine.session('support-1').qr, null);
    assert.equal(f.engine.session('support-1').challenge_id, null);
    assert.equal(JSON.stringify(await f.engine.events()).includes('private-qr-secret'), false);
});

test('terminal connection failure remains distinct from intentional stop', async (t) => {
    const f = fixture(t, { maxReconnect: 0 });
    await connect(f);
    f.sockets[0].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { statusCode: 408 } } });
    assert.equal(f.engine.session('rekrutmen-1').canonical_status, 'FAILED');
    await f.engine.stopSession('rekrutmen-1', false);
    assert.equal(f.engine.session('rekrutmen-1').canonical_status, 'STOPPED');
});

test('unconfirmed local teardown fails without reporting stopped', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    sock.end = () => { throw new Error('end failed'); };
    sock.ws.close = () => { throw new Error('close failed'); };
    await assert.rejects(f.engine.stopSession('rekrutmen-1', false), { errorCode: 'teardown_unconfirmed' });
    assert.equal(f.engine.session('rekrutmen-1').canonical_status, 'WORKING');
    assert.equal(f.engine.session('rekrutmen-1').desired_state, 'STOPPED');
    assert.equal((await f.engine.sendText('rekrutmen-1', payload)).status, 'failed');
});

test('private media dispatch is idempotent and rejects URL or oversized payloads', async (t) => {
    const f = fixture(t);
    await connect(f);
    for (const type of ['image', 'document', 'voice']) {
        const media = { type, phone: payload.phone, idempotency_key: `media-${type}`, media_base64: Buffer.from('media').toString('base64'),
            mimetype: type === 'image' ? 'image/png' : type === 'voice' ? 'audio/ogg' : 'application/pdf', filename: 'hello.pdf' };
        const result = await f.engine.sendMessage('rekrutmen-1', media);
        assert.equal(result.status, 'sent');
        assert.deepEqual(await f.engine.sendMessage('rekrutmen-1', media), result);
        await assert.rejects(f.engine.sendMessage('rekrutmen-1', { ...media, media_base64: Buffer.from('other').toString('base64') }), { errorCode: 'idempotency_conflict' });
    }
    assert.equal(f.sent.length, 3);
    assert.equal(f.sent[2].content.ptt, true);
    assert.throws(() => f.engine.sendMessage('rekrutmen-1', { ...payload, type: 'image', media_url: 'http://localhost/private' }), { errorCode: 'invalid_media' });
});

test('incoming messages and ACKs survive restart until hub acknowledges them', async (t) => {
    const f = fixture(t, { downloadMedia: async () => Buffer.from('image-bytes') });
    const sock = await connect(f);
    sock.ev.emit('messages.upsert', { type: 'notify', messages: [
        { key: { id: 'incoming-text', remoteJid: '6281234567890@s.whatsapp.net' }, message: { conversation: 'Hello' } },
        { key: { id: 'incoming-image', remoteJid: '6281234567890@s.whatsapp.net' }, message: { imageMessage: { mimetype: 'image/png', caption: 'Photo' } } },
        { key: { id: 'group-message', remoteJid: '123@g.us' }, message: { conversation: 'Excluded' } },
    ] });
    sock.sendMessage = async () => { throw new Error('Unknown dispatch'); };
    const sent = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(sent.status, 'unknown');
    sock.ev.emit('messages.update', [{ key: { id: sent.id, fromMe: true, remoteJid: '6281234567890@s.whatsapp.net' }, update: { status: 4 } }]);
    assert.equal(f.engine.messageStatus('rekrutmen-1', payload.idempotency_key).status, 'sent');
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    const events = (await recovered.events()).events;
    const inbound = events.filter((event) => event.event === 'message');
    assert.equal(inbound.length, 2);
    assert.equal(inbound[0].data.text, 'Hello');
    assert.equal(inbound[1].data.media_base64, Buffer.from('image-bytes').toString('base64'));
    assert.equal(inbound[1].data.raw_message, undefined);
    const ack = events.find((event) => event.event === 'message.ack');
    assert.equal(ack.data.ack, 'read');
    for (const event of events) recovered.acknowledgeEvent(event.id);
    assert.equal((await recovered.events()).events.length, 0);
    await recovered.shutdown();
});

test('offline append messages and repeated ACK stages are deduplicated through reconnect and acknowledged restart', async (t) => {
    const f = fixture(t);
    const sock = await connect(f);
    const key = { id: 'offline-message', remoteJid: '6281234567890@s.whatsapp.net', fromMe: false };
    const message = { key, message: { ephemeralMessage: { message: { conversation: 'Arrived while offline' } } } };
    sock.ev.emit('messages.upsert', { type: 'append', messages: [message,
        { ...message, key: { ...key, id: 'own-message', fromMe: true } },
        { ...message, key: { ...key, id: 'group-message', remoteJid: '123@g.us' } },
        { key: { ...key, id: 'protocol-message' }, message: { protocolMessage: { type: 5 } } },
    ] });
    sock.ev.emit('messages.upsert', { type: 'notify', messages: [message] });
    sock.ev.emit('messaging-history.set', { messages: [{ ...message, key: { ...key, id: 'old-history' } }] });
    const ackKey = { ...key, id: 'outbound-message', fromMe: true };
    sock.ev.emit('messages.update', [{ key: ackKey, update: { status: 3 } }]);
    sock.ev.emit('message-receipt.update', [{ key: ackKey, receipt: { receiptTimestamp: 123 } }]);
    sock.ev.emit('messages.update', [{ key: ackKey, update: { status: 4 } }]);
    let events = (await f.engine.events()).events;
    assert.deepEqual(events.filter((event) => event.event === 'message').map((event) => event.data.text), ['Arrived while offline']);
    assert.deepEqual(events.filter((event) => event.event === 'message.ack').map((event) => event.data.ack), ['delivered', 'read']);

    await f.engine.stopSession('rekrutmen-1', false);
    const reconnected = await connect(f);
    reconnected.ev.emit('messages.upsert', { type: 'append', messages: [message] });
    assert.equal((await f.engine.events()).events.filter((event) => event.event === 'message').length, 1);
    events = (await f.engine.events()).events;
    for (const event of events) f.engine.acknowledgeEvent(event.id);
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    await recovered.startSession('rekrutmen-1', { mode: 'qr' });
    const resumed = f.sockets.at(-1);
    resumed.ev.emit('connection.update', { connection: 'open' });
    resumed.ev.emit('messages.upsert', { type: 'append', messages: [message] });
    resumed.ev.emit('messages.update', [{ key: ackKey, update: { status: 3 } }, { key: ackKey, update: { status: 4 } }]);
    assert.equal((await recovered.events()).events.filter((event) => event.event !== 'session.status').length, 0);
    await recovered.shutdown();
});

test('pending media preserves its envelope and cursor while another session continues, then retries after restart', async (t) => {
    let attempts = 0;
    const f = fixture(t, { mediaRetryDelay: () => 60000, downloadMedia: async () => {
        if (++attempts === 1) throw new Error('Temporary download outage');
        return Buffer.from('recovered-image');
    } });
    const first = await connect(f, 'account-1');
    for (const event of (await f.engine.events()).events) f.engine.acknowledgeEvent(event.id);
    first.ev.emit('messages.upsert', { type: 'append', messages: [{
        key: { id: 'pending-image', remoteJid: '6281234567890@s.whatsapp.net' },
        message: { imageMessage: { mimetype: 'image/png', mediaKey: Buffer.from('secret') } },
    }] });
    const second = await connect(f, 'account-2');
    second.ev.emit('messages.upsert', { type: 'notify', messages: [{
        key: { id: 'later-text', remoteJid: '6281234567890@s.whatsapp.net' }, message: { conversation: 'Continue' },
    }] });
    second.ev.emit('messages.update', [{ key: { id: 'later-ack', fromMe: true }, update: { status: 3 } }]);
    const page = await f.engine.events();
    assert.deepEqual(new Set(page.events.map((event) => event.event)), new Set(['message', 'message.ack', 'session.status']));
    assert.equal(page.events.every((event) => event.session_id === 'account-2'), true);
    assert.equal(page.next_cursor, '0', 'cursor must not skip the pending media');
    assert.equal(attempts, 1);
    const spool = createEventSpool(path.join(f.root, 'runner-events'));
    const pending = spool.list().find((event) => event.data.message_id === 'pending-image');
    assert.ok(pending.data.raw_message);
    assert.equal(pending.media_attempts, 1);
    assert.throws(() => f.engine.acknowledgeEvent(pending.id), { errorCode: 'media_pending' });
    for (const event of page.events) f.engine.acknowledgeEvent(event.id);
    await f.engine.shutdown();

    const recovered = createWhatsAppEngine(f.options);
    assert.equal((await recovered.events(page.next_cursor)).events.length, 0);
    assert.equal(attempts, 1, 'durable backoff survives restart');
    pending.media_retry_at = new Date(Date.now() - 1).toISOString();
    spool.update(pending);
    const retried = await recovered.events(page.next_cursor);
    assert.equal(attempts, 2);
    assert.equal(retried.events.length, 1);
    assert.equal(retried.events[0].id, pending.id);
    assert.equal(retried.events[0].data.media_base64, Buffer.from('recovered-image').toString('base64'));
    assert.equal(retried.events[0].data.raw_message, undefined);
    recovered.acknowledgeEvent(pending.id);
    assert.equal((await recovered.events()).events.length, 0);
    await recovered.shutdown();
});

test('more than one page of pending media cannot hide a later ready message', async (t) => {
    let attempts = 0;
    const f = fixture(t, { downloadMedia: async () => { attempts++; throw new Error('Download outage'); } });
    const sock = await connect(f);
    for (const event of (await f.engine.events()).events) f.engine.acknowledgeEvent(event.id);
    sock.ev.emit('messages.upsert', { type: 'append', messages: Array.from({ length: 101 }, (_, index) => ({
        key: { id: `image-${index}`, remoteJid: '6281234567890@s.whatsapp.net' }, message: { imageMessage: { mimetype: 'image/png' } },
    })) });
    sock.ev.emit('messages.upsert', { type: 'notify', messages: [{
        key: { id: 'later-text', remoteJid: '6281234567890@s.whatsapp.net' }, message: { conversation: 'Still received' },
    }] });
    const page = await f.engine.events();
    assert.equal(page.events.length, 1);
    assert.equal(page.events[0].data.text, 'Still received');
    assert.equal(page.next_cursor, '0');
    assert.equal(attempts, 1, 'each poll performs at most one media retrieval');
    assert.equal(createEventSpool(path.join(f.root, 'runner-events')).list('0', 200).filter((event) => event.data.media_pending).length, 101);
});

test('a hung media download is bounded and concurrent polls still deliver unrelated events', async (t) => {
    const entered = deferred();
    let aborted = false;
    const f = fixture(t, { mediaDownloadTimeoutMs: 25, downloadMedia: async (_message, _sock, { signal }) => {
        signal.addEventListener('abort', () => { aborted = true; });
        entered.resolve();
        return new Promise(() => {});
    } });
    const sock = await connect(f);
    for (const event of (await f.engine.events()).events) f.engine.acknowledgeEvent(event.id);
    sock.ev.emit('messages.upsert', { type: 'append', messages: [
        { key: { id: 'hung-image', remoteJid: '6281234567890@s.whatsapp.net' }, message: { imageMessage: { mimetype: 'image/png' } } },
        { key: { id: 'ready-text', remoteJid: '6281234567890@s.whatsapp.net' }, message: { conversation: 'Ready' } },
    ] });
    const first = f.engine.events();
    await entered.promise;
    const concurrent = await f.engine.events();
    assert.equal(concurrent.events.length, 1);
    assert.equal(concurrent.events[0].data.text, 'Ready');
    f.engine.acknowledgeEvent(concurrent.events[0].id);
    assert.equal((await first).events.length, 0);
    assert.equal(aborted, true);
    const pending = createEventSpool(path.join(f.root, 'runner-events')).list()[0];
    assert.equal(pending.data.media_pending, true);
    assert.equal(pending.media_attempts, 1);
});

test('HTTP lifecycle endpoints preserve paused authentication and no-store challenge responses', async (t) => {
    const f = fixture(t);
    const id = '11223344-5566-7788-9900-aabbccddeeff';
    const started = await request(f.engine, 'POST', `/sessions/${id}/start`, { generation: 1 });
    assert.equal(started.body.canonical_status, 'STARTING');
    const stopped = await request(f.engine, 'POST', `/sessions/${id}/stop`, { generation: 2 });
    assert.equal(stopped.body.desired_state, 'STOPPED');
    assert.equal((await request(f.engine, 'POST', `/sessions/${id}/start`, { generation: 1 })).code, 409);
    const events = await request(f.engine, 'GET', '/events');
    assert.ok(events.body.events.length);
    assert.equal((await request(f.engine, 'DELETE', `/events/${events.body.events[0].id}`)).code, 200);
});

test('control-plane startup grants override stale local RUNNING intent before any socket is restored', async (t) => {
    const f = fixture(t);
    await f.engine.startSession('support-1', { mode: 'qr', generation: 8 });
    await f.engine.startSession('support-2', { mode: 'qr', generation: 2 });
    await f.engine.shutdown();
    const recovered = createWhatsAppEngine(f.options);
    await recovered.reconcileStartup([{ id: 'support-1', desired_state: 'STOPPED', generation: 9 }]);
    assert.equal(f.sockets.length, 2, 'neither stopped nor unlisted sockets were restored');
    assert.equal(recovered.session('support-1').desired_state, 'STOPPED');
    assert.equal(recovered.session('support-1').generation, 9);
    assert.equal(recovered.session('support-2').desired_state, 'STOPPED');
    await recovered.shutdown();
});

test('a newer direct command arriving during startup reconciliation wins over old restore grants', async (t) => {
    const f = fixture(t);
    await f.engine.startSession('support-1', { mode: 'pairing', phone: '081234567890', generation: 10 });
    await f.engine.reconcileStartup([{ id: 'support-1', desired_state: 'STOPPED', generation: 9 }]);
    assert.equal(f.engine.session('support-1').desired_state, 'RUNNING');
    assert.equal(f.engine.session('support-1').mode, 'pairing');
    assert.equal(f.sockets.length, 1);
});

test('an ACK received during an unresolved send wins over the later send timeout', async (t) => {
    const f = fixture(t, { sendTimeoutMs: 30 });
    const sock = await connect(f);
    sock.sendMessage = async (_jid, _content, options) => {
        sock.ev.emit('messages.update', [{ key: { id: options.messageId, fromMe: true }, update: { status: 3 } }]);
        return new Promise(() => {});
    };
    const result = await f.engine.sendText('rekrutmen-1', payload);
    assert.equal(result.status, 'sent');
    assert.equal(f.engine.messageStatus('rekrutmen-1', payload.idempotency_key).status, 'sent');
    const ack = (await f.engine.events()).events.find((event) => event.event === 'message.ack');
    assert.equal(ack.data.idempotency_key, payload.idempotency_key);
});

test('a replacement authentication mode cancels an older start still loading credentials', async (t) => {
    const auth = deferred();
    const entered = deferred();
    let loads = 0;
    const f = fixture(t, { useAuthState: () => { loads += 1; entered.resolve(); return loads === 1 ? auth.promise : Promise.resolve(f.auth); } });
    const old = f.engine.startSession('support-1', { mode: 'qr', generation: 1 });
    const cancelled = assert.rejects(old, { errorCode: 'session_cancelled' });
    await entered.promise;
    const replacement = f.engine.startSession('support-1', { mode: 'pairing', phone: '081234567890', generation: 2 });
    auth.resolve(f.auth);
    await Promise.all([cancelled, replacement]);
    assert.equal(f.sockets.length, 1);
    assert.equal(f.engine.session('support-1').mode, 'pairing');
    assert.equal(f.engine.session('support-1').generation, 2);
});

test('startup grants do not consume pending logout, delete, or pairing operation generations', async (t) => {
    const f = fixture(t);
    await f.engine.reconcileStartup([
        { id: 'logout-1', desired_state: 'STOPPED', generation: 2 },
        { id: 'delete-1', desired_state: 'STOPPED', generation: 3 },
        { id: 'pairing-1', desired_state: 'RUNNING', generation: 4 },
    ]);
    const logout = await f.engine.stopSession('logout-1', true, { generation: 2 });
    assert.equal(logout.canonical_status, 'STOPPED');
    const deleted = await f.engine.stopSession('delete-1', true, { generation: 3, deleting: true });
    assert.equal(deleted.canonical_status, 'STOPPED');
    const pairing = await f.engine.startSession('pairing-1', { mode: 'pairing', phone: '081234567890', generation: 4 });
    assert.equal(pairing.mode, 'pairing');
    assert.throws(() => f.engine.stopSession('pairing-1', true, { generation: 4 }), { errorCode: 'stale_generation' });
    assert.throws(() => f.engine.startSession('logout-1', { mode: 'qr', generation: 2 }), { errorCode: 'stale_generation' });
});

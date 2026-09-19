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

test('restoration skips unregistered credentials and restores registered QR credentials', async (t) => {
    const f = fixture(t);
    f.auth.state.creds = { registered: false, me: { id: '6281234567890:3@s.whatsapp.net' }, account: { details: 'paired' } };
    const credsFile = path.join(f.options.sessionRoot, 'rekrutmen-1', 'creds.json');
    fs.mkdirSync(path.dirname(credsFile), { recursive: true });
    fs.writeFileSync(credsFile, '{}');
    await f.engine.restoreSessions();
    assert.equal(f.sockets.length, 1);
    assert.equal(f.engine.session('rekrutmen-1').status, 'connecting');
    f.auth.state.creds = { registered: false };
    await f.engine.stopSession('rekrutmen-1', false);
    await f.engine.restoreSessions();
    assert.equal(f.sockets.length, 1);
    assert.equal(f.engine.session('rekrutmen-1').status, 'disconnected');
});

test('restoration supports Hub-assigned IDs for any application and ignores invalid directories', async (t) => {
    const f = fixture(t);
    f.auth.state.creds.registered = true;
    const id = 'wgh-11223344-5566-7788-9900-aabbccddeeff';
    for (const directory of [id, 'support-1', 'INVALID']) {
        const root = path.join(f.options.sessionRoot, directory);
        fs.mkdirSync(root, { recursive: true });
        fs.writeFileSync(path.join(root, 'creds.json'), '{}');
    }
    await f.engine.restoreSessions();
    assert.equal(f.sockets.length, 2);
    assert.equal(f.engine.session(id).status, 'connecting');
    assert.equal(f.engine.session('support-1').status, 'connecting');
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

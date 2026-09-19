import fs from 'node:fs';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import {
    createSendJournal,
    EngineError,
    messageFingerprint,
    validateMessageKey,
    validateSessionId,
} from './send-journal.mjs';

async function withTimeout(promise, milliseconds, message) {
    let timer;

    try {
        return await Promise.race([
            promise,
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error(message)), milliseconds);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}

export function formatPhone(phone) {
    let digits = String(phone || '').replace(/[^\d]/g, '');

    if (digits.startsWith('0')) {
        digits = `62${digits.slice(1)}`;
    } else if (digits.startsWith('8')) {
        digits = `62${digits}`;
    }

    return digits;
}

function validPhone(phone) {
    if (typeof phone !== 'string' || !/^[+\d\s().-]+$/.test(phone)) {
        return false;
    }

    return /^[1-9][0-9]{7,14}$/.test(formatPhone(phone));
}

function registered(state) {
    return state?.creds?.registered === true || Boolean(state?.creds?.account && state?.creds?.me?.id);
}

function failure(message, errorCode, retryable = false) {
    return { ok: false, status: 'failed', message, retryable, error_code: errorCode };
}

function journalResult(entry) {
    if (entry.status === 'pending') {
        return {
            ok: false,
            status: 'unknown',
            id: entry.messageId,
            retryable: false,
            error_code: 'delivery_unknown',
            message: 'Hasil pengiriman WhatsApp belum dapat dipastikan. Pesan tidak dikirim ulang otomatis.',
        };
    }

    return {
        ok: entry.status === 'sent',
        status: entry.status,
        id: entry.messageId,
        ...(entry.message ? { message: entry.message } : {}),
        ...(entry.error_code ? { error_code: entry.error_code } : {}),
        retryable: entry.retryable === true,
    };
}

export function createWhatsAppEngine({
    sessionRoot,
    journalRoot = path.join(path.dirname(sessionRoot), 'whatsapp-messages'),
    makeSocket,
    useAuthState,
    fetchVersion,
    cacheKeys = (keys) => keys,
    browser = ['Ubuntu', 'Chrome', '22.04.4'],
    qrToDataURL,
    logger = { info() {}, warn() {}, error() {} },
    disconnectReasons,
    maxReconnect = 8,
    versionTimeoutMs = 5000,
    pairingTimeoutMs = 10000,
    logoutTimeoutMs = 5000,
    sendTimeoutMs = 30000,
    reconnectDelay = (attempt) => Math.min(30000, 1000 * (2 ** attempt)),
    journal = createSendJournal(journalRoot),
}) {
    const sessions = new Map();
    const operations = new Map();
    const epochs = new Map();
    const sends = new Map();
    let versionPromise;
    let shuttingDown = false;

    const sessionDirectory = (id) => path.join(sessionRoot, validateSessionId(id));
    const epoch = (id) => epochs.get(id) || 0;

    function serialize(id, operation) {
        const pending = (operations.get(id) || Promise.resolve()).catch(() => {}).then(operation);
        operations.set(id, pending);
        pending.finally(() => {
            if (operations.get(id) === pending) {
                operations.delete(id);
            }
        }).catch(() => {});

        return pending;
    }

    function recordFor(id) {
        if (!sessions.has(id)) {
            sessions.set(id, {
                id,
                status: 'disconnected',
                qr: null,
                pairingCode: null,
                phone: null,
                error: null,
                sock: null,
                generation: 0,
                qrSequence: 0,
                stopping: false,
                reconnectAttempt: 0,
                reconnectTimer: null,
                pairingRequested: false,
                mode: null,
                pairingPhone: null,
                saveQueue: Promise.resolve(),
                handlers: null,
            });
        }

        return sessions.get(id);
    }

    function publicSession(record) {
        return {
            ok: true,
            id: record.id,
            status: record.status,
            mode: record.mode,
            qr: record.qr,
            pairing_code: record.pairingCode,
            phone: record.phone,
            error: record.error,
            reconnect_attempt: record.reconnectAttempt,
        };
    }

    function invalidatePairing(record) {
        record.qrSequence += 1;
        record.qr = null;
        record.pairingCode = null;
        record.pairingRequested = false;
    }

    function cancelReconnect(record) {
        clearTimeout(record.reconnectTimer);
        record.reconnectTimer = null;
    }

    async function closeSocket(record, logout = false) {
        const sock = record.sock;
        let logoutConfirmed = false;

        if (sock) {
            if (logout && typeof sock.logout === 'function') {
                try {
                    await withTimeout(Promise.resolve().then(() => sock.logout()), logoutTimeoutMs, 'Logout WhatsApp melewati batas waktu.');
                    logoutConfirmed = true;
                } catch (error) {
                    logger.warn({ id: record.id, err: error.message }, 'Logout perangkat WhatsApp gagal.');
                }
            }

            if (record.handlers) {
                sock.ev.off('connection.update', record.handlers.connection);
                sock.ev.off('creds.update', record.handlers.credentials);
            }

            try {
                sock.end(undefined);
            } catch (error) {
                logger.warn({ id: record.id, err: error.message }, 'Socket WhatsApp gagal ditutup.');
            }

            try {
                sock.ws?.close?.();
            } catch (error) {
                logger.warn({ id: record.id, err: error.message }, 'WebSocket WhatsApp gagal ditutup.');
            }
        }

        record.sock = null;
        record.handlers = null;
        await record.saveQueue;

        return logoutConfirmed;
    }

    async function version() {
        if (!versionPromise) {
            versionPromise = withTimeout(
                Promise.resolve().then(() => fetchVersion({ timeout: versionTimeoutMs })),
                versionTimeoutMs,
                'Pengambilan versi WhatsApp melewati batas waktu.',
            ).then((result) => {
                if (Array.isArray(result?.version) && result.version.length === 3 && result.version.every(Number.isInteger)) {
                    return result.version;
                }

                return null;
            }).catch((error) => {
                logger.warn({ err: error.message }, 'Menggunakan versi bawaan Baileys.');

                return null;
            });
        }

        return versionPromise;
    }

    function assertActive(id, expectedEpoch, record) {
        if (shuttingDown || epoch(id) !== expectedEpoch || (record && (record.stopping || sessions.get(id) !== record))) {
            throw new EngineError('Permintaan koneksi WhatsApp dibatalkan.', 409, 'session_cancelled', true);
        }
    }

    async function startUnlocked(id, mode, phone, expectedEpoch, resetRetries) {
        assertActive(id, expectedEpoch);
        const record = recordFor(id);
        assertActive(id, expectedEpoch, record);

        if (record.sock && record.status === 'connected') {
            return publicSession(record);
        }

        const sameRequest = record.mode === mode && record.pairingPhone === phone;

        if (record.sock && !record.error && (sameRequest || mode === 'restore')
            && ['qr', 'pairing', 'connecting'].includes(record.status)) {
            return publicSession(record);
        }

        cancelReconnect(record);
        record.generation += 1;
        const generation = record.generation;
        invalidatePairing(record);
        await closeSocket(record);
        assertActive(id, expectedEpoch, record);

        record.mode = mode;
        record.pairingPhone = phone;
        record.status = mode === 'pairing' ? 'pairing' : 'connecting';
        record.error = null;

        if (resetRetries) {
            record.reconnectAttempt = 0;
        }

        fs.mkdirSync(sessionDirectory(id), { recursive: true, mode: 0o700 });
        let { state, saveCreds } = await useAuthState(sessionDirectory(id));
        assertActive(id, expectedEpoch, record);

        if (mode === 'restore' && !registered(state)) {
            record.status = 'disconnected';
            record.error = 'Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.';

            return publicSession(record);
        }

        if (!registered(state) && state.creds?.me) {
            fs.rmSync(sessionDirectory(id), { recursive: true, force: true });
            fs.mkdirSync(sessionDirectory(id), { recursive: true, mode: 0o700 });
            ({ state, saveCreds } = await useAuthState(sessionDirectory(id)));
            assertActive(id, expectedEpoch, record);
        }

        if (registered(state)) {
            record.status = 'connecting';
        }

        const selectedVersion = await version();
        assertActive(id, expectedEpoch, record);
        const sock = makeSocket({
            ...(selectedVersion ? { version: selectedVersion } : {}),
            auth: { creds: state.creds, keys: cacheKeys(state.keys, logger) },
            logger,
            printQRInTerminal: false,
            browser,
            syncFullHistory: false,
            markOnlineOnConnect: false,
            connectTimeoutMs: 30000,
            keepAliveIntervalMs: 15000,
            retryRequestDelayMs: 500,
            emitOwnEvents: false,
        });
        record.sock = sock;
        record.phone = registered(state) ? (state.creds.me?.id?.split('@')[0]?.split(':')[0] || record.phone) : phone;
        const isCurrent = () => !shuttingDown && epoch(id) === expectedEpoch && sessions.get(id) === record
            && !record.stopping && record.generation === generation && record.sock === sock;

        const requestPairing = async () => {
            if (!isCurrent() || mode !== 'pairing' || registered(state) || record.pairingRequested || record.pairingCode) {
                return;
            }

            record.pairingRequested = true;

            try {
                const code = await withTimeout(
                    Promise.resolve().then(() => sock.requestPairingCode(phone)),
                    pairingTimeoutMs,
                    'Pembuatan kode pairing melewati batas waktu.',
                );

                if (!isCurrent() || record.status === 'connected') {
                    return;
                }

                const raw = String(code || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                record.pairingCode = raw.length === 8 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
                record.status = 'pairing';
                record.error = null;
            } catch (error) {
                if (isCurrent()) {
                    record.error = error.message;
                    record.pairingRequested = false;
                }
            }
        };

        const onConnection = (update) => {
            if (!isCurrent()) {
                return;
            }

            const { connection, qr, lastDisconnect } = update;

            if (connection === 'open') {
                invalidatePairing(record);
                cancelReconnect(record);
                record.status = 'connected';
                record.phone = sock.user?.id?.split('@')[0]?.split(':')[0] || record.phone;
                record.error = null;
                record.reconnectAttempt = 0;

                return;
            }

            if (connection === 'close') {
                invalidatePairing(record);
                sock.ev.off('connection.update', onConnection);
                sock.ev.off('creds.update', onCredentials);
                record.sock = null;
                record.handlers = null;
                const code = lastDisconnect?.error?.output?.statusCode ?? lastDisconnect?.error?.statusCode;
                const clearAuth = [disconnectReasons.loggedOut, disconnectReasons.badSession,
                    disconnectReasons.multideviceMismatch, disconnectReasons.forbidden].includes(code);
                const canReconnect = !clearAuth && code !== disconnectReasons.connectionReplaced;
                record.error = clearAuth
                    ? 'Sesi WhatsApp berakhir. Scan QR atau minta kode pairing baru.'
                    : 'Koneksi WhatsApp terputus.';

                if (!canReconnect) {
                    record.status = 'disconnected';
                    record.reconnectAttempt = 0;

                    if (clearAuth) {
                        serialize(id, async () => {
                            await record.saveQueue;

                            if (epoch(id) === expectedEpoch && record.generation === generation) {
                                fs.rmSync(sessionDirectory(id), { recursive: true, force: true });
                            }
                        }).catch((error) => logger.error({ id, err: error.message }, 'Gagal menghapus sesi WhatsApp.'));
                    }

                    return;
                }

                record.reconnectAttempt += 1;

                if (record.reconnectAttempt > maxReconnect) {
                    record.status = 'disconnected';
                    record.error = 'Koneksi putus berulang. Hubungkan ulang WhatsApp.';

                    return;
                }

                record.status = 'connecting';
                const wait = code === disconnectReasons.restartRequired ? 500 : reconnectDelay(record.reconnectAttempt);
                record.reconnectTimer = setTimeout(() => {
                    if (epoch(id) !== expectedEpoch || record.stopping || record.generation !== generation || shuttingDown) {
                        return;
                    }

                    startSession(id, { mode, phone, resetRetries: false }).catch((error) => {
                        if (epoch(id) === expectedEpoch && !record.stopping) {
                            record.status = 'disconnected';
                            record.error = error.message;
                        }
                    });
                }, wait);
                record.reconnectTimer.unref?.();

                return;
            }

            if (qr && mode === 'pairing') {
                requestPairing().catch((error) => logger.error({ id, err: error.message }, 'Gagal meminta pairing.'));
            } else if (qr && mode === 'qr') {
                const sequence = ++record.qrSequence;

                Promise.resolve().then(() => qrToDataURL(qr, { margin: 1, width: 280 })).then((image) => {
                    if (isCurrent() && record.qrSequence === sequence && record.status !== 'connected') {
                        record.qr = image;
                        record.status = 'qr';
                        record.error = null;
                    }
                }).catch((error) => {
                    if (isCurrent() && record.qrSequence === sequence && record.status !== 'connected') {
                        record.error = error.message;
                    }
                });
            }
        };

        const onCredentials = () => {
            if (!isCurrent()) {
                return;
            }

            record.saveQueue = record.saveQueue.then(() => saveCreds()).catch((error) => {
                logger.error({ id, err: error.message }, 'Gagal menyimpan kredensial WhatsApp.');
                record.error = 'Kredensial WhatsApp tidak dapat disimpan.';
            });
        };

        record.handlers = { connection: onConnection, credentials: onCredentials };
        sock.ev.on('creds.update', onCredentials);
        sock.ev.on('connection.update', onConnection);

        return publicSession(record);
    }

    function startSession(id, { mode, phone = null, resetRetries = true } = {}) {
        validateSessionId(id);

        if (!['qr', 'pairing', 'restore'].includes(mode)) {
            throw new EngineError('Pilih mode QR atau pairing WhatsApp.', 422, 'invalid_mode');
        }

        if (mode === 'pairing' && !validPhone(phone)) {
            throw new EngineError('Nomor HP pairing WhatsApp tidak valid.', 422, 'invalid_phone');
        }

        const expectedEpoch = epoch(id);

        return serialize(id, () => startUnlocked(id, mode, mode === 'pairing' ? formatPhone(phone) : null, expectedEpoch, resetRetries))
            .catch((error) => {
                const record = sessions.get(id);

                if (record && epoch(id) === expectedEpoch && !record.stopping) {
                    record.status = 'disconnected';
                    record.error = error.message;
                }

                throw error;
            });
    }

    function stopSession(id, logout = true) {
        validateSessionId(id);
        epochs.set(id, epoch(id) + 1);
        const record = sessions.get(id);

        if (record) {
            record.stopping = true;
            record.generation += 1;
            cancelReconnect(record);
            invalidatePairing(record);
        }

        return serialize(id, async () => {
            let logoutConfirmed = false;

            if (record) {
                logoutConfirmed = await closeSocket(record, logout);
                record.status = 'disconnected';
            }

            if (logout) {
                fs.rmSync(sessionDirectory(id), { recursive: true, force: true });
            }

            if (sessions.get(id) === record) {
                sessions.delete(id);
            }

            return {
                ok: true,
                status: 'disconnected',
                ...(logout ? {
                    logout_confirmed: logoutConfirmed,
                    message: logoutConfirmed
                        ? 'Nomor WhatsApp berhasil diputuskan.'
                        : 'Sesi lokal sudah diputuskan, tetapi logout dari WhatsApp belum terkonfirmasi. Hapus perangkat CESA dari menu Perangkat tertaut di HP bila masih tercantum.',
                } : {}),
            };
        });
    }

    function connectedRecord(id) {
        const record = sessions.get(id);

        if (!record?.sock || record.stopping || record.status !== 'connected') {
            if (!shuttingDown && !record?.stopping && !record?.sock
                && fs.existsSync(path.join(sessionDirectory(id), 'creds.json'))) {
                startSession(id, { mode: 'restore' }).catch((error) => {
                    logger.warn({ id, err: error.message }, 'Pemulihan sesi sebelum pengiriman gagal.');
                });
            }

            throw new EngineError('Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.', 409, 'not_connected', true);
        }

        return record;
    }

    async function performSend(id, phone, text, key, fingerprint) {
        const existing = journal.read(id, key);

        if (existing) {
            if (existing.fingerprint !== fingerprint) {
                throw new EngineError('Kunci pengiriman sudah digunakan untuk pesan berbeda.', 409, 'idempotency_conflict');
            }

            return journalResult(existing);
        }

        let record;

        try {
            record = connectedRecord(id);
        } catch (error) {
            return failure(error.message, error.errorCode || 'connection_failed', true);
        }

        const messageId = `3EB0${randomBytes(18).toString('hex').toUpperCase()}`;
        const sock = record.sock;
        const entry = {
            version: 1,
            sessionId: id,
            key,
            fingerprint,
            messageId,
            status: 'pending',
            retryable: false,
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString(),
        };

        try {
            journal.write(entry);
        } catch (error) {
            return failure(error.message, 'journal_unavailable', true);
        }

        let sentPersisted = false;
        const sending = Promise.resolve()
            .then(() => sock.sendMessage(`${phone}@s.whatsapp.net`, { text }, { messageId }))
            .then(() => {
                entry.status = 'sent';
                entry.updatedAt = new Date().toISOString();
                delete entry.message;
                delete entry.error_code;

                try {
                    journal.write(entry);
                    sentPersisted = true;
                } catch (error) {
                    logger.error({ id, messageId, err: error.message }, 'Konfirmasi pengiriman WhatsApp belum tersimpan.');

                    throw error;
                }

                return journalResult(entry);
            });

        try {
            return await withTimeout(sending, sendTimeoutMs, 'Pengiriman WhatsApp melewati batas waktu.');
        } catch (error) {
            if (sentPersisted) {
                return journalResult(entry);
            }

            entry.status = 'unknown';
            entry.message = 'Hasil pengiriman WhatsApp belum dapat dipastikan. Pesan tidak dikirim ulang otomatis.';
            entry.error_code = 'delivery_unknown';
            entry.updatedAt = new Date().toISOString();

            try {
                journal.write(entry);
            } catch (journalError) {
                logger.error({ id, err: journalError.message }, 'Hasil pengiriman WhatsApp belum tersimpan.');
            }

            logger.warn({ id, messageId, err: error.message }, 'Hasil pengiriman WhatsApp tidak pasti.');

            return journalResult(entry);
        }
    }

    function sendText(id, { phone, text, idempotency_key: key } = {}) {
        validateSessionId(id);
        validateMessageKey(key);

        if (!validPhone(phone)) {
            throw new EngineError('Nomor tujuan WhatsApp tidak valid.', 422, 'invalid_phone');
        }

        if (typeof text !== 'string' || text.trim() === '' || text.length > 65536) {
            throw new EngineError('Isi pesan WhatsApp tidak valid.', 422, 'invalid_text');
        }

        const digits = formatPhone(phone);
        const fingerprint = messageFingerprint(digits, text);
        const operationKey = `${id}\u0000${key}`;
        const pending = sends.get(operationKey);

        if (pending) {
            if (pending.fingerprint !== fingerprint) {
                throw new EngineError('Kunci pengiriman sudah digunakan untuk pesan berbeda.', 409, 'idempotency_conflict');
            }

            return pending.promise;
        }

        const promise = Promise.resolve().then(() => performSend(id, digits, text, key, fingerprint));
        sends.set(operationKey, { fingerprint, promise });
        promise.finally(() => {
            if (sends.get(operationKey)?.promise === promise) {
                sends.delete(operationKey);
            }
        }).catch(() => {});

        return promise;
    }

    async function checkNumber(id, phone) {
        validateSessionId(id);
        if (!validPhone(phone)) {
            throw new EngineError('Nomor WhatsApp tidak valid.', 422, 'invalid_phone');
        }
        const record = recordFor(id);
        if (record.status !== 'connected' || !record.sock) {
            throw new EngineError('Nomor pengirim belum terhubung.', 409, 'not_connected', true);
        }
        const results = await withTimeout(record.sock.onWhatsApp(formatPhone(phone)), 10000, 'Pengecekan nomor melewati batas waktu.');
        if (!Array.isArray(results)) {
            return { ok: false, registered: null };
        }
        const result = results[0];
        return { ok: true, registered: results.length === 0 ? false : (typeof result?.exists === 'boolean' ? result.exists : null) };
    }

    function messageStatus(id, key) {
        const entry = journal.read(validateSessionId(id), validateMessageKey(key));

        if (!entry) {
            throw new EngineError('Pengiriman WhatsApp belum tercatat.', 404, 'message_not_found', true);
        }

        return journalResult(entry);
    }

    async function restoreSessions() {
        fs.mkdirSync(sessionRoot, { recursive: true, mode: 0o700 });
        const entries = fs.readdirSync(sessionRoot, { withFileTypes: true });

        await Promise.allSettled(entries.filter((entry) => {
            if (!entry.isDirectory()) return false;
            try { validateSessionId(entry.name); return true; } catch { return false; }
        })
            .map(async (entry) => {
                if (fs.existsSync(path.join(sessionDirectory(entry.name), 'creds.json'))) {
                    try {
                        await startSession(entry.name, { mode: 'restore' });
                    } catch (error) {
                        logger.error({ id: entry.name, err: error.message }, 'Gagal memulihkan sesi WhatsApp.');
                    }
                }
            }));
    }

    async function shutdown() {
        shuttingDown = true;
        await Promise.allSettled([...sessions.keys()].map((id) => stopSession(id, false)));
    }

    return {
        startSession,
        stopSession,
        sendText,
        checkNumber,
        messageStatus,
        restoreSessions,
        shutdown,
        session(id) {
            validateSessionId(id);

            return publicSession(recordFor(id));
        },
        health() {
            const records = [...sessions.values()];

            return {
                ok: true,
                sessions: records.length,
                connected: records.filter((record) => record.status === 'connected').length,
            };
        },
    };
}

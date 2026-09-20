import fs from 'node:fs';
import path from 'node:path';
import { randomBytes, randomUUID, createHash } from 'node:crypto';
import { createStateStore, createEventSpool } from './durable-store.mjs';
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
    stateStore = createStateStore(path.join(path.dirname(sessionRoot), 'runner-state')),
    eventSpool = createEventSpool(path.join(path.dirname(sessionRoot), 'runner-events')),
    clearAuth = async (id) => fs.rmSync(path.join(sessionRoot, id), { recursive: true, force: true }),
    downloadMedia,
    mediaDownloadTimeoutMs = 15000,
    mediaRetryDelay = (attempt) => Math.min(15 * 60 * 1000, 5000 * (2 ** Math.min(attempt - 1, 8))),
    qrTtlMs = 60000,
    pairingTtlMs = 180000,
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
    const commandsReceived = new Set();
    let versionPromise;
    let shuttingDown = false;
    let mediaDownloadInFlight = false;

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
                ...(stateStore.read(id) || { desired_state: 'STOPPED', generation: 0, revision: 0, observed_at: null }),
                status: 'disconnected',
                qr: null,
                pairingCode: null,
                phone: stateStore.read(id)?.phone || null,
                error: null,
                sock: null,
                socketGeneration: 0,
                qrSequence: 0,
                stopping: false,
                reconnectAttempt: 0,
                reconnectTimer: null,
                pairingRequested: false,
                mode: null,
                pairingPhone: null,
                saveQueue: Promise.resolve(),
                handlers: null,
                challengeId: null,
                challengeExpiresAt: null,
                authenticatedPhone: stateStore.read(id)?.authenticatedPhone || null,
            });
        }

        return sessions.get(id);
    }

    function canonicalStatus(record) {
        if (record.status === 'connected') return 'WORKING';
        if (['qr', 'pairing'].includes(record.status)) return 'SCAN_QR_CODE';
        if (record.status === 'connecting') return 'STARTING';
        return record.error && record.desired_state === 'RUNNING' ? 'FAILED' : 'STOPPED';
    }

    function publicSession(record, secrets = true) {
        const expired = record.challengeExpiresAt && Date.parse(record.challengeExpiresAt) <= Date.now();
        return {
            ok: true, id: record.id, status: record.status,
            canonical_status: canonicalStatus(record), desired_state: record.desired_state,
            generation: record.generation, revision: record.revision, observed_at: record.observed_at,
            engine_available: !shuttingDown, stale: shuttingDown,
            mode: record.mode, auth_method: ['qr', 'pairing'].includes(record.status) ? record.mode : null,
            ...(secrets ? { qr: expired ? null : record.qr, pairing_code: expired ? null : record.pairingCode,
                challenge_id: expired ? null : record.challengeId, expires_at: expired ? null : record.challengeExpiresAt } : {}),
            phone: record.phone, phone_number: record.authenticatedPhone,
            error: record.error, reconnect_attempt: record.reconnectAttempt,
        };
    }

    function persist(record) {
        stateStore.write({ id: record.id, generation: record.generation, revision: record.revision,
            desired_state: record.desired_state, observed_at: record.observed_at,
            command: record.command, phone: record.phone, authenticatedPhone: record.authenticatedPhone, deleted: record.deleted || false });
    }

    function emit(record, event, data) {
        const identity = event === 'message' || event === 'message.ack'
            ? JSON.stringify([record.id, event, data.chat_id || '', data.message_id, data.from_me === true, event === 'message.ack' ? data.ack : null])
            : undefined;
        return eventSpool.append({ event, session_id: record.id, generation: record.generation,
            revision: record.revision, occurred_at: new Date().toISOString(), data }, identity);
    }

    function changed(record) {
        record.revision += 1;
        record.observed_at = new Date().toISOString();
        // Event precedes the snapshot: a crash cannot publish a state without an event.
        emit(record, 'session.status', publicSession(record, false));
        persist(record);
    }

    function command(record, action, requestedGeneration, desiredState) {
        const next = requestedGeneration ?? record.generation + 1;
        if (!Number.isSafeInteger(next) || next < 0) throw new EngineError('Invalid generation.', 422, 'invalid_generation');
        const startupGrant = record.command === `grant:${desiredState}`;
        if (next < record.generation || (next === record.generation && record.command !== action && !startupGrant)) {
            throw new EngineError('This session command has been superseded.', 409, 'stale_generation');
        }
        commandsReceived.add(record.id);
        record.generation = next;
        record.command = action;
        record.desired_state = desiredState;
        record.deleted = false;
        persist(record);
    }

    function invalidatePairing(record) {
        record.qrSequence += 1;
        record.qr = null;
        record.pairingCode = null;
        record.pairingRequested = false;
        record.challengeId = null;
        record.challengeExpiresAt = null;
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
                sock.ev.off('messages.upsert', record.handlers.messages);
                sock.ev.off('messages.update', record.handlers.acknowledgements);
                sock.ev.off('message-receipt.update', record.handlers.receipts);
            }

            let closed = false;
            try {
                await Promise.resolve(sock.end(undefined));
                closed = true;
            } catch (error) {
                logger.warn({ id: record.id, err: error.message }, 'Socket WhatsApp gagal ditutup.');
            }

            try {
                if (sock.ws?.close) {
                    await Promise.resolve(sock.ws.close());
                    closed = true;
                }
            } catch (error) {
                logger.warn({ id: record.id, err: error.message }, 'WebSocket WhatsApp gagal ditutup.');
            }
            if (!closed) throw new EngineError('Socket teardown was not confirmed.', 503, 'teardown_unconfirmed', true);
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

        const sameRequest = record.mode === mode && record.pairingPhone === phone
            && !(record.challengeExpiresAt && Date.parse(record.challengeExpiresAt) <= Date.now());

        if (record.sock && !record.error && (sameRequest || mode === 'restore')
            && ['qr', 'pairing', 'connecting'].includes(record.status)) {
            return publicSession(record);
        }

        cancelReconnect(record);
        record.socketGeneration += 1;
        const generation = record.socketGeneration;
        invalidatePairing(record);
        await closeSocket(record);
        assertActive(id, expectedEpoch, record);

        record.mode = mode;
        record.pairingPhone = phone;
        record.status = mode === 'pairing' ? 'pairing' : 'connecting';
        record.error = null;
        changed(record);

        if (resetRetries) {
            record.reconnectAttempt = 0;
        }

        fs.mkdirSync(sessionDirectory(id), { recursive: true, mode: 0o700 });
        let { state, saveCreds } = await useAuthState(sessionDirectory(id));
        assertActive(id, expectedEpoch, record);

        if (mode === 'restore' && !registered(state)) {
            record.status = 'disconnected';
            record.error = 'Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.';
            changed(record);

            return publicSession(record);
        }

        if (!registered(state) && state.creds?.me) {
            await clearAuth(id);
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
            && !record.stopping && record.socketGeneration === generation && record.sock === sock;

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
                record.challengeId = randomUUID();
                record.challengeExpiresAt = new Date(Date.now() + pairingTtlMs).toISOString();
                changed(record);
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
                record.authenticatedPhone = record.phone ? `+${record.phone}` : null;
                record.error = null;
                record.reconnectAttempt = 0;
                changed(record);

                return;
            }

            if (connection === 'close') {
                invalidatePairing(record);
                sock.ev.off('connection.update', onConnection);
                sock.ev.off('creds.update', onCredentials);
                sock.ev.off('messages.upsert', onMessages);
                sock.ev.off('messages.update', onAcknowledgements);
                sock.ev.off('message-receipt.update', onReceipts);
                record.sock = null;
                record.handlers = null;
                const code = lastDisconnect?.error?.output?.statusCode ?? lastDisconnect?.error?.statusCode;
                const mustClearAuth = [disconnectReasons.loggedOut, disconnectReasons.badSession,
                    disconnectReasons.multideviceMismatch, disconnectReasons.forbidden].includes(code);
                const canReconnect = !mustClearAuth && code !== disconnectReasons.connectionReplaced;
                record.error = mustClearAuth
                    ? 'Sesi WhatsApp berakhir. Scan QR atau minta kode pairing baru.'
                    : 'Koneksi WhatsApp terputus.';

                if (!canReconnect) {
                    record.status = 'disconnected';
                    record.reconnectAttempt = 0;
                    changed(record);

                    if (mustClearAuth) {
                        serialize(id, async () => {
                            await record.saveQueue;

                            if (epoch(id) === expectedEpoch && record.socketGeneration === generation) {
                                await clearAuth(id);
                            }
                        }).catch((error) => logger.error({ id, err: error.message }, 'Gagal menghapus sesi WhatsApp.'));
                    }

                    return;
                }

                record.reconnectAttempt += 1;

                if (record.reconnectAttempt > maxReconnect) {
                    record.status = 'disconnected';
                    record.error = 'Koneksi putus berulang. Hubungkan ulang WhatsApp.';
                    changed(record);

                    return;
                }

                record.status = 'connecting';
                changed(record);
                const wait = code === disconnectReasons.restartRequired ? 500 : reconnectDelay(record.reconnectAttempt);
                record.reconnectTimer = setTimeout(() => {
                    if (epoch(id) !== expectedEpoch || record.stopping || record.socketGeneration !== generation || shuttingDown) {
                        return;
                    }

                    startSession(id, { mode, phone, resetRetries: false }).catch((error) => {
                        if (epoch(id) === expectedEpoch && !record.stopping) {
                            record.status = 'disconnected';
                            record.error = error.message;
                            changed(record);
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
                        record.challengeId = randomUUID();
                        record.challengeExpiresAt = new Date(Date.now() + qrTtlMs).toISOString();
                        changed(record);
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
                record.status = 'disconnected';
                changed(record);
                closeSocket(record).catch((closeError) => logger.error({ id, err: closeError.message }, 'Failed to close failed authentication socket.'));
            });
        };

        function onMessages(update) {
            // Baileys delivers offline arrivals as append. Bulk history uses
            // messaging-history.set, which this lean runner deliberately does not consume.
            if (!isCurrent() || !['notify', 'append'].includes(update.type)) return;
            for (const message of update.messages || []) {
                if (!isCurrent() || !message.key?.id || !message.key.remoteJid || message.key.fromMe
                    || !/@(s.whatsapp.net|lid)$/.test(message.key.remoteJid)) continue;
                let content = message.message || {};
                for (let i = 0; i < 4; i++) {
                    const wrapped = content.ephemeralMessage || content.viewOnceMessage || content.viewOnceMessageV2 || content.documentWithCaptionMessage;
                    if (!wrapped?.message) break;
                    content = wrapped.message;
                }
                const media = content.imageMessage || content.documentMessage || content.audioMessage;
                const type = content.imageMessage ? 'image' : content.documentMessage ? 'document' : content.audioMessage ? 'voice' : 'text';
                const text = content.conversation || content.extendedTextMessage?.text || '';
                if (!media && !text) continue;
                const data = { message_id: message.key.id, chat_id: message.key.remoteJid,
                    from: message.key.remoteJid.split('@')[0], from_me: false, type, text,
                    caption: media?.caption || null, mimetype: media?.mimetype || null,
                    filename: media?.fileName || null,
                    ...(media ? Number(media.fileLength || 0) > 16 * 1024 * 1024
                        ? { media_error: 'media_too_large' } : { media_pending: true, raw_message: message } : {}) };
                emit(record, 'message', data);
            }
        }
        function emitAck(key, status) {
            if (!isCurrent() || !key?.id || !key.fromMe || !Number.isInteger(status) || status < 2) return;
            const ack = status >= 5 ? 'played' : status >= 4 ? 'read' : status >= 3 ? 'delivered' : 'accepted';
            const journalEntry = journal.findByMessageId?.(id, key.id);
            emit(record, 'message.ack', { message_id: key.id, idempotency_key: journalEntry?.key || null, chat_id: key.remoteJid, from_me: true, ack, status });
            try { journal.confirm?.(id, key.id); }
            catch (error) { logger.error({ id, message_id: key.id, err: error.message }, 'ACK is durable but journal confirmation remains pending.'); }
        }
        const onAcknowledgements = (updates) => { for (const item of updates || []) emitAck(item.key, item.update?.status); };
        const onReceipts = (updates) => { for (const item of updates || []) emitAck(item.key,
            item.receipt?.playedTimestamp ? 5 : item.receipt?.readTimestamp ? 4 : item.receipt?.receiptTimestamp ? 3 : 0); };
        record.handlers = { connection: onConnection, credentials: onCredentials,
            messages: onMessages, acknowledgements: onAcknowledgements, receipts: onReceipts };
        sock.ev.on('messages.upsert', onMessages);
        sock.ev.on('messages.update', onAcknowledgements);
        sock.ev.on('message-receipt.update', onReceipts);
        sock.ev.on('creds.update', onCredentials);
        sock.ev.on('connection.update', onConnection);

        return publicSession(record);
    }

    function startSession(id, { mode, phone = null, resetRetries = true, generation } = {}) {
        validateSessionId(id);

        if (!['qr', 'pairing', 'restore'].includes(mode)) {
            throw new EngineError('Pilih mode QR atau pairing WhatsApp.', 422, 'invalid_mode');
        }

        if (mode === 'pairing' && !validPhone(phone)) {
            throw new EngineError('Nomor HP pairing WhatsApp tidak valid.', 422, 'invalid_phone');
        }

        const record = recordFor(id);
        const action = `start:${mode}${mode === 'pairing' ? `:${formatPhone(phone)}` : ''}`;
        const replaced = record.command && record.command !== action && record.status !== 'connected';
        if (resetRetries) {
            command(record, action, generation, 'RUNNING');
            if (replaced) epochs.set(id, epoch(id) + 1);
        }
        record.stopping = false;
        const expectedEpoch = epoch(id);

        return serialize(id, () => startUnlocked(id, mode, mode === 'pairing' ? formatPhone(phone) : null, expectedEpoch, resetRetries))
            .catch((error) => {
                const record = sessions.get(id);

                if (record && epoch(id) === expectedEpoch && !record.stopping) {
                    record.status = 'disconnected';
                    record.error = error.message;
                    changed(record);
                }

                throw error;
            });
    }

    function stopSession(id, logout = true, { generation, deleting = false, preserveIntent = false } = {}) {
        validateSessionId(id);
        const record = recordFor(id);
        if (!preserveIntent) command(record, deleting ? 'delete' : logout ? 'logout' : 'stop', generation, 'STOPPED');
        epochs.set(id, epoch(id) + 1);
        record.stopping = true;
        record.socketGeneration += 1;
        cancelReconnect(record);
        invalidatePairing(record);
        return serialize(id, async () => {
            const logoutConfirmed = await closeSocket(record, logout);
            if (logout) await clearAuth(id);
            record.status = 'disconnected';
            record.error = null;
            record.authenticatedPhone = logout ? null : record.authenticatedPhone;
            record.deleted = deleting;
            if (!preserveIntent) changed(record);
            sessions.delete(id);
            return { ...publicSession(record), ...(logout ? {
                logout_confirmed: logoutConfirmed,
                message: logoutConfirmed ? 'Nomor WhatsApp berhasil diputuskan.'
                    : 'Sesi lokal sudah diputuskan, tetapi logout dari WhatsApp belum terkonfirmasi. Hapus perangkat dari menu Perangkat tertaut di HP bila masih tercantum.',
            } : {}) };
        });
    }

    function connectedRecord(id) {
        const record = sessions.get(id);

        if (!record?.sock || record.stopping || record.desired_state !== 'RUNNING' || record.status !== 'connected') {
            throw new EngineError('Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.', 409, 'not_connected', true);
        }

        return record;
    }

    async function performSend(id, phone, content, key, fingerprint, expectedGeneration) {
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
            if (expectedGeneration !== undefined && record.generation !== expectedGeneration) throw new EngineError('Session generation changed.', 409, 'stale_generation');
        } catch (error) {
            return failure(error.message, error.errorCode || 'connection_failed', error.retryable === true);
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
            .then(() => {
                if (connectedRecord(id) !== record || record.sock !== sock || (expectedGeneration !== undefined && record.generation !== expectedGeneration)) {
                    throw new EngineError('Session changed before dispatch.', 409, 'stale_generation');
                }
                return sock.sendMessage(`${phone}@s.whatsapp.net`, content, { messageId });
            })
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

            const confirmed = journal.read(id, key);
            if (confirmed?.status === 'sent') return journalResult(confirmed);
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

    function sendText(id, { phone, text, idempotency_key: key, generation } = {}) {
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

        const promise = Promise.resolve().then(() => performSend(id, digits, { text }, key, fingerprint, generation));
        sends.set(operationKey, { fingerprint, promise });
        promise.finally(() => {
            if (sends.get(operationKey)?.promise === promise) {
                sends.delete(operationKey);
            }
        }).catch(() => {});

        return promise;
    }

    function sendMessage(id, payload = {}) {
        if (payload.type === 'text') return sendText(id, payload);
        validateSessionId(id);
        validateMessageKey(payload.idempotency_key);
        if (!validPhone(payload.phone)) throw new EngineError('Invalid recipient.', 422, 'invalid_phone');
        if (!['image', 'document', 'voice'].includes(payload.type)) throw new EngineError('Unsupported message type.', 422, 'invalid_type');
        if (typeof payload.media_base64 !== 'string' || !/^[A-Za-z0-9+/]+={0,2}$/.test(payload.media_base64)
            || payload.media_base64.length % 4 !== 0 || payload.media_base64.length > 22369624) {
            throw new EngineError('Invalid media bytes.', 422, 'invalid_media');
        }
        const bytes = Buffer.from(payload.media_base64, 'base64');
        if (bytes.length === 0 || bytes.length > 16 * 1024 * 1024) throw new EngineError('Media exceeds 16 MB.', 413, 'media_too_large');
        if (payload.type === 'image' && !['image/jpeg', 'image/png'].includes(payload.mimetype)) throw new EngineError('Unsupported image type.', 422, 'invalid_media');
        if (payload.type === 'voice' && payload.mimetype !== 'audio/ogg; codecs=opus' && payload.mimetype !== 'audio/ogg') throw new EngineError('Voice must be OGG/Opus.', 422, 'invalid_media');
        if (payload.caption != null && (typeof payload.caption !== 'string' || payload.caption.length > 65536)) throw new EngineError('Invalid caption.', 422, 'invalid_media');
        const content = payload.type === 'voice' ? { audio: bytes, ptt: true, mimetype: 'audio/ogg; codecs=opus' }
            : payload.type === 'image' ? { image: bytes, caption: payload.caption || '', mimetype: payload.mimetype }
                : { document: bytes, caption: payload.caption || '', mimetype: payload.mimetype || 'application/octet-stream', fileName: String(payload.filename || 'document').slice(0, 255) };
        const digits = formatPhone(payload.phone);
        const fingerprint = createHash('sha256').update(JSON.stringify([digits, payload.type, payload.caption || '',
            payload.filename || '', payload.mimetype || '', createHash('sha256').update(bytes).digest('hex')])).digest('hex');
        const key = `${id}\u0000${payload.idempotency_key}`;
        const pending = sends.get(key);
        if (pending) {
            if (pending.fingerprint !== fingerprint) throw new EngineError('Idempotency conflict.', 409, 'idempotency_conflict');
            return pending.promise;
        }
        const promise = Promise.resolve().then(() => performSend(id, digits, content, payload.idempotency_key, fingerprint, payload.generation));
        sends.set(key, { fingerprint, promise });
        promise.finally(() => { if (sends.get(key)?.promise === promise) sends.delete(key); }).catch(() => {});
        return promise;
    }

    async function events(after = '0') {
        const ready = [];
        let responseBytes = 0;
        let hydrated = false;
        let blocked = false;
        let cursor = after;
        // Scan past pending attachments even when they occupy more than a full response page.
        for (const event of eventSpool.iterate(after)) {
            if (event.data.media_pending) {
                if (hydrated || mediaDownloadInFlight || Date.parse(event.media_retry_at || '') > Date.now()) {
                    blocked = true;
                    continue;
                }
                hydrated = true;
                mediaDownloadInFlight = true;
                const controller = new AbortController();
                try {
                    if (!downloadMedia) throw new Error('Media downloader unavailable.');
                    const raw = JSON.parse(JSON.stringify(event.data.raw_message), (_key, value) =>
                        value?.type === 'Buffer' && Array.isArray(value.data) ? Buffer.from(value.data) : value);
                    const media = await withTimeout(Promise.resolve().then(() => downloadMedia(raw,
                        sessions.get(event.session_id)?.sock, { signal: controller.signal })),
                    mediaDownloadTimeoutMs, 'Incoming media retrieval timed out.');
                    if (!Buffer.isBuffer(media) || media.length > 16 * 1024 * 1024) throw new Error('Incoming media exceeds 16 MB.');
                    event.data.media_base64 = media.toString('base64');
                    delete event.data.media_pending;
                    delete event.data.raw_message;
                    delete event.media_retry_at;
                    delete event.media_attempts;
                    eventSpool.update(event);
                } catch (error) {
                    if (Date.now() - Date.parse(event.occurred_at) > 24 * 60 * 60 * 1000 || /exceeds 16 MB/.test(error.message)) {
                        event.data.media_error = /exceeds 16 MB/.test(error.message) ? 'media_too_large' : 'media_unavailable';
                        delete event.data.media_pending;
                        delete event.data.raw_message;
                        delete event.media_retry_at;
                        delete event.media_attempts;
                        eventSpool.update(event);
                    } else {
                        event.media_attempts = (event.media_attempts || 0) + 1;
                        event.media_retry_at = new Date(Date.now() + mediaRetryDelay(event.media_attempts)).toISOString();
                        eventSpool.update(event);
                        // Preserve the attachment without holding up unrelated messages or sessions.
                        logger.warn({ event_id: event.id, err: error.message }, 'Incoming media retrieval pending.');
                        blocked = true;
                        continue;
                    }
                } finally {
                    controller.abort();
                    mediaDownloadInFlight = false;
                }
            }
            const encodedBytes = Buffer.byteLength(JSON.stringify(event));
            if (responseBytes + encodedBytes > 24 * 1024 * 1024 && ready.length) break;
            responseBytes += encodedBytes;
            ready.push(event);
            if (!blocked) cursor = event.cursor;
            if (ready.length >= 100) break;
        }
        // Never let an after cursor skip an unacknowledged pending attachment.
        return { events: ready, next_cursor: cursor };
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

    async function reconcileStartup(grants) {
        if (!Array.isArray(grants)) throw new Error('Invalid control-plane restore grants.');
        const approved = new Map();
        for (const grant of grants) {
            validateSessionId(grant.id);
            if (!Number.isSafeInteger(grant.generation) || grant.generation < 0
                || !['RUNNING', 'STOPPED'].includes(grant.desired_state)) throw new Error('Invalid control-plane restore grant.');
            approved.set(grant.id, grant);
        }
        for (const state of stateStore.list()) {
            const grant = approved.get(state.id);
            if (!grant && !commandsReceived.has(state.id)) await stopSession(state.id, false);
        }
        for (const grant of approved.values()) {
            const record = recordFor(grant.id);
            if (commandsReceived.has(grant.id) && grant.generation <= record.generation) continue; // A newer direct command already arrived.
            record.generation = grant.generation;
            record.desired_state = grant.desired_state;
            // A restore grant expresses intent, not completion of a concrete hub operation.
            // Its first matching concrete command may consume this generation once.
            record.command = `grant:${grant.desired_state}`;
            persist(record);
            if (grant.desired_state === 'STOPPED') {
                await stopSession(grant.id, false, { preserveIntent: true });
                changed(recordFor(grant.id));
            }
        }
        await restoreSessions();
    }

    async function restoreSessions() {
        // Legacy credentials without explicit persisted RUNNING intent are never restored.
        await Promise.allSettled(stateStore.list().filter((state) => state.desired_state === 'RUNNING' && !state.deleted && !sessions.get(state.id)?.sock)
            .map(async (state) => {
                try { await startSession(state.id, { mode: 'qr', resetRetries: false }); }
                catch (error) { logger.error({ id: state.id, err: error.message }, 'Session restoration failed.'); }
            }));
    }

    async function shutdown() {
        shuttingDown = true;
        await Promise.allSettled([...sessions.keys()].map((id) => stopSession(id, false, { preserveIntent: true })));
    }

    return {
        startSession,
        stopSession,
        sendText,
        sendMessage,
        events,
        acknowledgeEvent: eventSpool.acknowledge,
        checkNumber,
        messageStatus,
        restoreSessions,
        reconcileStartup,
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

import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pino from 'pino';
import QRCode from 'qrcode';
import makeWASocket, {
    Browsers,
    BufferJSON,
    initAuthCreds,
    proto,
    downloadMediaMessage,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    useMultiFileAuthState,
} from '@whiskeysockets/baileys';
import { createWhatsAppEngine } from './engine.mjs';
import { createRequestHandler } from './http-server.mjs';
import { createSqlAuthStore } from './sql-auth.mjs';
import { createStateStore, createEventSpool } from './durable-store.mjs';

const token = (process.env.WAG_BAILEYS_TOKEN || '').trim();
if (!token) {
    throw new Error('Set WAG_BAILEYS_TOKEN before starting the runner.');
}

const port = Number(process.env.WAG_BAILEYS_PORT || process.env.REKRUTMEN_WA_ENGINE_PORT || 3318);
const host = process.env.WAG_BAILEYS_HOST || process.env.REKRUTMEN_WA_ENGINE_HOST || '127.0.0.1';
const sessionRoot = process.env.WAG_BAILEYS_SESSION_ROOT
    || process.env.REKRUTMEN_WA_SESSION_ROOT
    || path.join(path.dirname(fileURLToPath(import.meta.url)), 'sessions');
const journalRoot = process.env.WAG_BAILEYS_JOURNAL_ROOT
    || process.env.REKRUTMEN_WA_JOURNAL_ROOT
    || path.join(path.dirname(sessionRoot), 'whatsapp-messages');
const logLevel = process.env.WAG_BAILEYS_LOG_LEVEL || process.env.REKRUTMEN_WA_LOG_LEVEL || 'info';

const logger = pino({ level: logLevel });
const authMode = process.env.WAG_BAILEYS_AUTH_MODE || 'sql';
let authStore;
let restoreReady = false;
let restoreTimer;
const controlUrl = process.env.WAG_HUB_CONTROL_URL?.replace(/\/+$/, '');
if (authMode === 'sql' && !controlUrl) throw new Error('Set WAG_HUB_CONTROL_URL to the hub base URL for authoritative startup reconciliation.');
if (authMode === 'sql') {
    const { createPool } = await import('mysql2/promise');
    authStore = await createSqlAuthStore({
        pool: createPool({
            host: process.env.WAG_BAILEYS_DB_HOST || '127.0.0.1',
            port: Number(process.env.WAG_BAILEYS_DB_PORT || 3306),
            database: process.env.WAG_BAILEYS_DB_DATABASE,
            user: process.env.WAG_BAILEYS_DB_USERNAME,
            password: process.env.WAG_BAILEYS_DB_PASSWORD,
            connectionLimit: 5,
            ...(process.env.WAG_BAILEYS_DB_TLS === 'true' ? { ssl: { rejectUnauthorized: true } } : {}),
        }),
        key: process.env.WAG_BAILEYS_AUTH_KEY, initAuthCreds, BufferJSON, proto,
    });
} else if (authMode !== 'file' || process.env.NODE_ENV === 'production') {
    throw new Error('Production requires WAG_BAILEYS_AUTH_MODE=sql; file mode is explicitly opt-in for development only.');
}
const engine = createWhatsAppEngine({
    sessionRoot,
    journalRoot,
    maxReconnect: Number(process.env.WAG_BAILEYS_MAX_RECONNECT || process.env.REKRUTMEN_WA_MAX_RECONNECT || 8),
    makeSocket: makeWASocket,
    useAuthState: authStore?.useAuthState || useMultiFileAuthState,
    ...(authStore ? { clearAuth: authStore.clear } : {}),
    stateStore: createStateStore(process.env.WAG_BAILEYS_STATE_ROOT || path.join(path.dirname(sessionRoot), 'runner-state')),
    eventSpool: createEventSpool(process.env.WAG_BAILEYS_EVENT_ROOT || path.join(path.dirname(sessionRoot), 'runner-events')),
    async downloadMedia(message, sock, { signal: requestSignal } = {}) {
        const signal = AbortSignal.any([AbortSignal.timeout(15000), ...(requestSignal ? [requestSignal] : [])]);
        const context = sock?.updateMediaMessage ? { logger, async reuploadRequest(message) {
            signal.throwIfAborted();
            return Promise.race([sock.updateMediaMessage(message), new Promise((_, reject) =>
                signal.addEventListener('abort', () => reject(new Error('Media retrieval timed out.')), { once: true }))]);
        } } : undefined;
        const stream = await downloadMediaMessage(message, 'stream', { options: { signal, timeout: 15000, maxRedirects: 0 } }, context);
        const chunks = [];
        let size = 0;
        try {
            for await (const chunk of stream) {
                size += chunk.length;
                if (size > 16 * 1024 * 1024) throw new Error('Incoming media exceeds 16 MB.');
                chunks.push(chunk);
            }
        } finally { stream.destroy?.(); }
        return Buffer.concat(chunks);
    },
    fetchVersion: fetchLatestBaileysVersion,
    cacheKeys: makeCacheableSignalKeyStore,
    browser: Browsers.ubuntu('Chrome'),
    qrToDataURL: QRCode.toDataURL,
    disconnectReasons: DisconnectReason,
    logger,
});
const handler = createRequestHandler(engine, { logger, token });
const server = http.createServer(async (request, response) => {
    if (request.url === '/health' && request.headers.authorization === `Bearer ${token}`) {
        if (!restoreReady) { response.writeHead(503, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ ok: false, error_code: 'control_plane_unavailable' })); return; }
        try { await authStore?.ready(); }
        catch { response.writeHead(503, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ ok: false, error_code: 'auth_store_unavailable' })); return; }
    }
    return handler(request, response);
});
let shutdownPromise;

async function shutdown(signal) {
    if (shutdownPromise) {
        return shutdownPromise;
    }

    logger.info({ signal }, 'WAG Hub Baileys engine stopping.');
    clearTimeout(restoreTimer);
    shutdownPromise = engine.shutdown().then(() => authStore?.close()).finally(() => {
        server.close(() => process.exit(0));
    });
    setTimeout(() => process.exit(0), 10000).unref();

    return shutdownPromise;
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (error) => {
    logger.error({ err: error?.message || String(error) }, 'unhandledRejection');
});
server.on('error', (error) => {
    logger.error({ err: error.message }, 'WAG Hub Baileys engine failed to bind port.');
    process.exitCode = 1;
    engine.shutdown().finally(() => process.exit(1));
});
server.listen(port, host, () => {
    logger.info({ host, port, sessionRoot, pid: process.pid }, 'WAG Hub Baileys engine ready');
    async function restore() {
        try {
            if (controlUrl) {
                const response = await fetch(`${controlUrl}/internal/engine/desired-sessions`, {
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
                    signal: AbortSignal.timeout(5000), redirect: 'error',
                });
                if (!response.ok) throw new Error(`Control plane returned ${response.status}.`);
                const snapshot = await response.json();
                if (shutdownPromise) return;
                await engine.reconcileStartup(snapshot.sessions);
            } else await engine.restoreSessions();
            restoreReady = true;
        } catch (error) {
            logger.error({ err: error.message }, 'Startup reconciliation pending; stale local intents are not restored.');
            if (!shutdownPromise) {
                restoreTimer = setTimeout(restore, 5000);
                restoreTimer.unref();
            }
        }
    }
    restore();
});

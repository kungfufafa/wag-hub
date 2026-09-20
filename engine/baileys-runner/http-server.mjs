import { timingSafeEqual } from 'node:crypto';
import { EngineError, validateSessionId } from './send-journal.mjs';

async function readJson(request) {
    const chunks = [];
    let size = 0;

    for await (const chunk of request) {
        size += chunk.length;

        if (size > 24 * 1024 * 1024) {
            throw new EngineError('Payload terlalu besar.', 413, 'payload_too_large');
        }

        chunks.push(chunk);
    }

    try {
        const value = chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {};

        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            throw new Error('Invalid object');
        }

        return value;
    } catch {
        throw new EngineError('Payload JSON tidak valid.', 400, 'invalid_json');
    }
}

function send(response, status, payload) {
    if (response.destroyed || response.writableEnded) {
        return;
    }

    const body = JSON.stringify(payload);
    response.writeHead(status, {
        'Content-Type': 'application/json',
        'Cache-Control': 'no-store',
        'Content-Length': Buffer.byteLength(body),
    });
    response.end(body);
}

export function createRequestHandler(engine, { logger = { error() {} }, startedAt = Date.now(), token = '' } = {}) {
    return async (request, response) => {
        try {
            const expected = Buffer.from(`Bearer ${token}`);
            const supplied = Buffer.from(request.headers.authorization || '');
            if (!token || expected.length !== supplied.length || !timingSafeEqual(expected, supplied)) {
                send(response, 401, { ok: false, status: 'failed', error_code: 'unauthenticated', retryable: false });
                return;
            }
            const url = new URL(request.url || '/', 'http://127.0.0.1');
            const pathname = url.pathname.replace(/\/+$/, '') || '/';

            if (request.method === 'GET' && pathname === '/health') {
                send(response, 200, { ...engine.health(), uptime_ms: Date.now() - startedAt, pid: process.pid });

                return;
            }

            if (request.method === 'POST' && pathname === '/sessions') {
                const payload = await readJson(request);

                if (!['qr', 'pairing'].includes(payload.mode)) {
                    throw new EngineError('Pilih mode QR atau pairing WhatsApp.', 422, 'invalid_mode');
                }

                send(response, 200, await engine.startSession(payload.id, { mode: payload.mode, phone: payload.phone }));

                return;
            }

            if (request.method === 'GET' && pathname === '/events') {
                send(response, 200, await engine.events(url.searchParams.get('after') || '0'));
                return;
            }
            const eventRoute = pathname.match(/^\/events\/([a-f0-9-]{36})$/);
            if (request.method === 'DELETE' && eventRoute) {
                send(response, 200, engine.acknowledgeEvent(eventRoute[1]));
                return;
            }
            const route = pathname.match(/^\/sessions\/([^/]+)(?:\/(send|messages|numbers|start|stop|logout|auth)(?:\/([^/]+))?)?$/);

            if (!route) {
                throw new EngineError('Not found', 404, 'not_found');
            }

            let id;
            let key;

            try {
                id = decodeURIComponent(route[1]);
                key = route[3] ? decodeURIComponent(route[3]) : null;
            } catch {
                throw new EngineError('Parameter URL tidak valid.', 400, 'invalid_url');
            }

            validateSessionId(id);

            if (!route[2] && request.method === 'GET') {
                send(response, 200, engine.session(id));
            } else if (!route[2] && request.method === 'DELETE') {
                const payload = await readJson(request);
                send(response, 200, await engine.stopSession(id, payload.logout !== false, { generation: payload.generation, deleting: true }));
            } else if (route[2] === 'start' && request.method === 'POST') {
                const payload = await readJson(request);
                send(response, 200, await engine.startSession(id, { ...payload, mode: payload.mode || 'qr' }));
            } else if (['stop', 'logout'].includes(route[2]) && request.method === 'POST') {
                const payload = await readJson(request);
                send(response, 200, await engine.stopSession(id, route[2] === 'logout', { generation: payload.generation }));
            } else if (route[2] === 'auth' && key === 'qr' && request.method === 'GET') {
                send(response, 200, engine.session(id));
            } else if (route[2] === 'auth' && key === 'pairing-code' && request.method === 'POST') {
                const payload = await readJson(request);
                send(response, 200, await engine.startSession(id, { ...payload, mode: 'pairing' }));
            } else if (route[2] === 'messages' && !key && request.method === 'POST') {
                const result = await engine.sendMessage(id, await readJson(request));
                send(response, result.status === 'failed' ? 409 : 200, result);
            } else if (route[2] === 'send'  && !key && request.method === 'POST') {
                const result = await engine.sendText(id, await readJson(request));
                send(response, result.status === 'failed' ? 409 : 200, result);
            } else if (route[2] === 'numbers' && key === 'check' && request.method === 'POST') {
                const payload = await readJson(request);
                send(response, 200, await engine.checkNumber(id, payload.phone));
            } else if (route[2] === 'messages' && key && request.method === 'GET') {
                send(response, 200, engine.messageStatus(id, key));
            } else {
                throw new EngineError('Not found', 404, 'not_found');
            }
        } catch (error) {
            logger.error({ err: error.message }, 'Engine gagal memproses permintaan.');
            send(response, error.statusCode || 500, {
                ok: false,
                status: 'failed',
                message: error.message || 'Engine WhatsApp gagal memproses permintaan.',
                retryable: error.retryable === true,
                error_code: error.errorCode || 'engine_error',
            });
        }
    };
}

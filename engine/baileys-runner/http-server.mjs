import { EngineError, validateSessionId } from './send-journal.mjs';

async function readJson(request) {
    const chunks = [];
    let size = 0;

    for await (const chunk of request) {
        size += chunk.length;

        if (size > 1000000) {
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
        'Content-Length': Buffer.byteLength(body),
    });
    response.end(body);
}

export function createRequestHandler(engine, { logger = { error() {} }, startedAt = Date.now() } = {}) {
    return async (request, response) => {
        try {
            const pathname = new URL(request.url || '/', 'http://127.0.0.1').pathname.replace(/\/+$/, '') || '/';

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

            const route = pathname.match(/^\/sessions\/([^/]+)(?:\/(send|messages)(?:\/([^/]+))?)?$/);

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
                send(response, 200, await engine.stopSession(id, payload.logout !== false));
            } else if (route[2] === 'send' && !key && request.method === 'POST') {
                const result = await engine.sendText(id, await readJson(request));
                send(response, result.status === 'failed' ? 409 : 200, result);
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

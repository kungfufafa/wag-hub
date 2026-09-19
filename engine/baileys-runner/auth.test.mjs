import test from 'node:test';
import assert from 'node:assert/strict';
import { Readable } from 'node:stream';
import { createRequestHandler } from './http-server.mjs';

for (const [name, token, authorization, expected] of [
    ['missing token config', '', 'Bearer secret', 401],
    ['missing authorization', 'secret', undefined, 401],
    ['wrong token', 'secret', 'Bearer public-app-token', 401],
    ['correct token', 'secret', 'Bearer secret', 200],
]) {
    test(name, async () => {
        let calls = 0;
        const request = Readable.from([]);
        Object.assign(request, { method: 'GET', url: '/health', headers: { authorization } });
        let status;
        let payload;
        const response = { writeHead(code) { status = code; }, end(body) { payload = JSON.parse(body); } };
        await createRequestHandler({ health() { calls++; return { ok: true }; } }, { token })(request, response);
        assert.equal(status, expected);
        assert.equal(calls, expected === 200 ? 1 : 0);
        assert.equal(payload.ok, expected === 200);
    });
}

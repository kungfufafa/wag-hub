import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createEventSpool } from './durable-store.mjs';

const message = { event: 'message', session_id: 'account-1', data: { message_id: 'provider-1', text: 'Hello' } };

function fixture(t) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'wag-event-spool-'));
    t.after(() => fs.rmSync(root, { recursive: true, force: true }));
    return { root, spool: createEventSpool(root) };
}

test('a replay repairs the event-write/receipt-write crash window without duplicating the event', (t) => {
    const { root, spool } = fixture(t);
    const event = spool.append(message, 'account-1:provider-1');
    const receipt = path.join(root, 'deduplication', `${event.id}.json`);
    fs.unlinkSync(receipt); // Crash after event fsync, before receipt fsync.
    const recovered = createEventSpool(root);
    assert.equal(recovered.append(message, 'account-1:provider-1'), null);
    assert.equal(recovered.list().length, 1);
    assert.equal(fs.existsSync(receipt), true);
    recovered.acknowledge(event.id);
    assert.equal(createEventSpool(root).append(message, 'account-1:provider-1'), null);
    assert.equal(recovered.list().length, 0);
    assert.equal(fs.readFileSync(receipt, 'utf8').includes('Hello'), false, 'receipt retains no message content');
});

test('acknowledgement repairs a missing dedup receipt before removing the only durable event', (t) => {
    const { root, spool } = fixture(t);
    const event = spool.append(message, 'account-1:provider-1');
    fs.unlinkSync(path.join(root, 'deduplication', `${event.id}.json`));
    createEventSpool(root).acknowledge(event.id);
    const recovered = createEventSpool(root);
    assert.equal(recovered.list().length, 0);
    assert.equal(recovered.append(message, 'account-1:provider-1'), null);
    assert.ok(recovered.append({ ...message, session_id: 'account-2' }, 'account-2:provider-1'));
    assert.equal(recovered.list().length, 1, 'provider identities are scoped to their account');
});

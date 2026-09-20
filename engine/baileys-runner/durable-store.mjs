import fs from 'node:fs';
import path from 'node:path';
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { EngineError, validateSessionId } from './send-journal.mjs';

export function atomicJson(file, value) {
    const directory = path.dirname(file);
    fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
    const temporary = `${file}.${randomBytes(8).toString('hex')}.tmp`;
    let fd;
    try {
        fd = fs.openSync(temporary, 'wx', 0o600);
        fs.writeFileSync(fd, JSON.stringify(value));
        fs.fsyncSync(fd);
        fs.closeSync(fd);
        fd = undefined;
        fs.renameSync(temporary, file);
        if (process.platform !== 'win32') {
            fd = fs.openSync(directory, 'r');
            fs.fsyncSync(fd);
            fs.closeSync(fd);
            fd = undefined;
        }
    } finally {
        if (fd !== undefined) fs.closeSync(fd);
        fs.rmSync(temporary, { force: true });
    }
}

function readJson(file, fallback = null) {
    try { return JSON.parse(fs.readFileSync(file, 'utf8')); }
    catch (error) { if (error.code === 'ENOENT') return fallback; throw error; }
}

export function createStateStore(root) {
    return {
        read(id) { return readJson(path.join(root, `${validateSessionId(id)}.json`)); },
        write(state) { atomicJson(path.join(root, `${validateSessionId(state.id)}.json`), state); },
        list() {
            if (!fs.existsSync(root)) return [];
            return fs.readdirSync(root).filter((file) => file.endsWith('.json')).map((file) => readJson(path.join(root, file)));
        },
    };
}

export function createEventSpool(root) {
    const counterFile = path.join(root, 'cursor');
    let sequence = BigInt(readJson(counterFile, '0'));
    function filename(event) { return path.join(root, `${event.cursor}-${event.id}.json`); }
    const receiptFile = (id) => path.join(root, 'deduplication', `${id}.json`);
    function files(after = '0') {
        if (typeof after !== 'string' || !/^\d{1,20}$/.test(after)) throw new EngineError('Invalid event cursor.', 422, 'invalid_cursor');
        if (!fs.existsSync(root)) return [];
        return fs.readdirSync(root).filter((file) => /^\d{20}-[a-f0-9-]+\.json$/.test(file)
            && BigInt(file.slice(0, 20)) > BigInt(after)).sort();
    }
    function *iterate(after = '0') {
        for (const file of files(after)) {
            const row = readJson(path.join(root, file));
            if (row) yield row; // An overlapping poll may already have acknowledged this row.
        }
    }
    function remember(event) {
        if (event.deduplicated) atomicJson(receiptFile(event.id), { id: event.id, cursor: event.cursor });
    }
    return {
        append(event, deduplicationKey) {
            const digest = deduplicationKey ? createHash('sha256').update(deduplicationKey).digest('hex') : null;
            const id = digest ? `${digest.slice(0, 8)}-${digest.slice(8, 12)}-${digest.slice(12, 16)}-${digest.slice(16, 20)}-${digest.slice(20, 32)}` : randomUUID();
            if (digest) {
                if (fs.existsSync(receiptFile(id))) return null;
                // Recover a crash between the durable event write and its dedup receipt.
                const existing = files().find((file) => file.endsWith(`-${id}.json`));
                if (existing) {
                    remember(readJson(path.join(root, existing)));
                    return null;
                }
            }
            sequence += 1n;
            atomicJson(counterFile, sequence.toString());
            const row = { ...event, id, cursor: sequence.toString().padStart(20, '0'), ...(digest ? { deduplicated: true } : {}) };
            atomicJson(filename(row), row);
            // The event must precede its receipt: no receipt may suppress an event never persisted.
            remember(row);
            return row;
        },
        update(event) { atomicJson(filename(event), event); },
        iterate,
        list(after = '0', limit = 100) {
            const rows = [];
            let bytes = 0;
            for (const row of iterate(after)) {
                if (rows.length >= limit) break;
                bytes += Buffer.byteLength(JSON.stringify(row));
                if (bytes > 24 * 1024 * 1024 && rows.length) break;
                rows.push(row);
            }
            return rows;
        },
        acknowledge(id) {
            if (!/^[a-f0-9-]{36}$/.test(id)) throw new EngineError('Invalid event ID.', 422, 'invalid_event');
            if (fs.existsSync(root)) for (const file of fs.readdirSync(root)) {
                if (file.endsWith(`-${id}.json`)) {
                    const target = path.join(root, file);
                    const event = readJson(target);
                    if (event.data?.media_pending) throw new EngineError('Media retrieval is pending.', 409, 'media_pending');
                    // Also recover the event-write/receipt-write crash window before deletion.
                    remember(event);
                    fs.unlinkSync(target);
                }
            }
            return { ok: true };
        },
    };
}

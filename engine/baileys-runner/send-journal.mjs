import fs from 'node:fs';
import path from 'node:path';
import { createHash, randomBytes } from 'node:crypto';

export class EngineError extends Error {
    constructor(message, statusCode = 500, errorCode = 'engine_error', retryable = false) {
        super(message);
        this.statusCode = statusCode;
        this.errorCode = errorCode;
        this.retryable = retryable;
    }
}

export function validateSessionId(id) {
    if (typeof id !== 'string' || !(/^[a-z][a-z0-9-]{1,46}$/.test(id) || /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(id)) || id.includes('--') || id.endsWith('-')) {
        throw new EngineError('ID sesi WhatsApp tidak valid.', 422, 'invalid_session');
    }

    return id;
}

export function validateMessageKey(key) {
    if (typeof key !== 'string' || key.length === 0 || key.length > 200 || key.trim() !== key
        || key === '.' || key === '..' || /[\u0000-\u001f\u007f]/.test(key)) {
        throw new EngineError('Kunci pengiriman WhatsApp tidak valid.', 422, 'invalid_idempotency_key');
    }

    return key;
}

export function messageFingerprint(phone, text) {
    return createHash('sha256').update(JSON.stringify([phone, text])).digest('hex');
}

export function createSendJournal(root) {
    function filename(id, key) {
        validateSessionId(id);
        validateMessageKey(key);

        return path.join(root, id, `${createHash('sha256').update(key).digest('hex')}.json`);
    }

    const journal = {
        findByMessageId(id, messageId) {
            const directory = path.join(root, validateSessionId(id));
            if (!fs.existsSync(directory)) return null;
            for (const file of fs.readdirSync(directory).filter((name) => name.endsWith('.json'))) {
                let entry;
                try { entry = JSON.parse(fs.readFileSync(path.join(directory, file), 'utf8')); }
                catch { continue; } // A different damaged record cannot suppress this ACK.
                if (entry.sessionId === id && entry.messageId === messageId && typeof entry.key === 'string') return entry;
            }
            return null;
        },
        confirm(id, messageId) {
            const entry = journal.findByMessageId(id, messageId);
            if (!entry || entry.status === 'sent') return entry;
            entry.status = 'sent';
            entry.updatedAt = new Date().toISOString();
            delete entry.error_code;
            delete entry.message;
            journal.write(entry);
            return entry;
        },
        read(id, key) {
            const file = filename(id, key);
            let content;

            try {
                content = fs.readFileSync(file, 'utf8');
            } catch (error) {
                if (error.code === 'ENOENT') {
                    return null;
                }

                throw new EngineError('Jurnal pengiriman WhatsApp tidak dapat dibaca.', 503, 'journal_unavailable');
            }

            try {
                const entry = JSON.parse(content);

                if (entry.version !== 1 || entry.sessionId !== id || entry.key !== key
                    || typeof entry.fingerprint !== 'string' || typeof entry.messageId !== 'string'
                    || !['pending', 'sent', 'unknown', 'failed'].includes(entry.status)) {
                    throw new Error('Invalid journal entry');
                }

                return entry;
            } catch {
                throw new EngineError('Jurnal pengiriman WhatsApp rusak; pengiriman tidak diulang.', 409, 'journal_corrupt');
            }
        },

        write(entry) {
            const file = filename(entry.sessionId, entry.key);
            const directory = path.dirname(file);
            const temporary = `${file}.${randomBytes(8).toString('hex')}.tmp`;
            let descriptor;

            try {
                fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
                descriptor = fs.openSync(temporary, 'wx', 0o600);
                fs.writeFileSync(descriptor, JSON.stringify(entry));
                fs.fsyncSync(descriptor);
                fs.closeSync(descriptor);
                descriptor = undefined;
                fs.renameSync(temporary, file);

                if (process.platform !== 'win32') {
                    descriptor = fs.openSync(directory, 'r');
                    fs.fsyncSync(descriptor);
                    fs.closeSync(descriptor);
                    descriptor = undefined;
                }
            } catch {
                throw new EngineError('Jurnal pengiriman WhatsApp tidak dapat disimpan.', 503, 'journal_unavailable', true);
            } finally {
                if (descriptor !== undefined) {
                    fs.closeSync(descriptor);
                }
            }
        },
    };
    return journal;
}

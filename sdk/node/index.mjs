/**
 * Minimal WAG Hub client for Node.js 18+.
 *
 * @example
 * import { WagClient } from '@wag-hub/client';
 *
 * const wag = WagClient.fromEnv();
 * const result = await wag.messages.send({
 *   recipient: '6281234567890',
 *   text: 'Hello from WAG Hub',
 *   idempotencyKey: 'order-123',
 * });
 */
export class WagClient {
  /**
   * @param {{ baseUrl: string, token: string, defaultConnectionId?: string | null, fetch?: typeof fetch }} options
   */
  constructor({ baseUrl, token, defaultConnectionId = null, fetch: fetchImpl = globalThis.fetch }) {
    if (!baseUrl || !token) {
      throw new Error('WAG Hub client requires baseUrl and token.');
    }

    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.token = token;
    this.defaultConnectionId = defaultConnectionId;
    this.fetch = fetchImpl;
  }

  static fromEnv(env = process.env) {
    return new WagClient({
      baseUrl: env.WAG_URL ?? '',
      token: env.WAG_TOKEN ?? '',
      defaultConnectionId: env.WAG_CONNECTION_ID ?? null,
    });
  }

  messages() {
    return new WagMessagesClient(this);
  }

  connections() {
    return new WagConnectionsClient(this);
  }

  /**
   * @param {string} path
   * @param {{ method?: string, body?: unknown, idempotencyKey?: string, headers?: Record<string, string> }} options
   */
  async request(path, { method = 'GET', body, idempotencyKey, headers = {} } = {}) {
    const requestHeaders = {
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      ...headers,
    };

    if (body !== undefined) {
      requestHeaders['Content-Type'] = 'application/json';
    }

    if (idempotencyKey) {
      requestHeaders['Idempotency-Key'] = idempotencyKey;
    }

    const response = await this.fetch(`${this.baseUrl}/api/v1${path}`, {
      method,
      headers: requestHeaders,
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
      const error = new Error(payload.message ?? `WAG Hub request failed (${response.status})`);
      error.status = response.status;
      error.code = payload.error?.code ?? 'request_failed';
      error.payload = payload;
      throw error;
    }

    return payload;
  }
}

export class WagMessagesClient {
  /** @param {WagClient} client */
  constructor(client) {
    this.client = client;
  }

  /**
   * @param {{
   *   recipient: string,
   *   text: string,
   *   idempotencyKey: string,
   *   connectionId?: string | null,
   *   purpose?: string,
   *   mode?: 'sync' | 'async',
   *   useDefaultConnection?: boolean,
   * }} params
   */
  async send({
    recipient,
    text,
    idempotencyKey,
    connectionId = this.client.defaultConnectionId,
    purpose = 'notification',
    mode = 'sync',
    useDefaultConnection = false,
  }) {
    const body = {
      recipient: { type: 'phone', value: recipient },
      message: { type: 'text', text },
      purpose,
      mode,
    };

    if (connectionId) {
      body.connection_id = connectionId;
    } else if (!useDefaultConnection) {
      body.route_key = 'default';
    }

    const headers = {};

    if (!connectionId && useDefaultConnection) {
      headers['X-WAG-Use-Default-Connection'] = 'true';
    }

    return this.client.request('/messages', {
      method: 'POST',
      body,
      idempotencyKey,
      headers,
    });
  }

  /** @param {string} messageId */
  async get(messageId) {
    return this.client.request(`/messages/${messageId}`);
  }
}

export class WagConnectionsClient {
  /** @param {WagClient} client */
  constructor(client) {
    this.client = client;
  }

  async list() {
    return this.client.request('/connections');
  }

  /** @param {string} connectionId */
  async get(connectionId) {
    return this.client.request(`/connections/${connectionId}`);
  }

  /**
   * @param {{
   *   name: string,
   *   type: 'managed_number' | 'provider_route',
   *   driver?: string,
   *   configuration?: Record<string, unknown>,
   *   isDefault?: boolean,
   * }} params
   */
  async create({ name, type, driver, configuration, isDefault = false }) {
    return this.client.request('/connections', {
      method: 'POST',
      body: {
        name,
        type,
        driver,
        configuration,
        is_default: isDefault,
      },
    });
  }

  /** @param {string} connectionId */
  async setup(connectionId, { mode, phone } = { mode: 'qr' }) {
    return this.client.request(`/connections/${connectionId}/setup`, {
      method: 'POST',
      body: { mode, phone },
    });
  }

  /** @param {string} connectionId */
  async test(connectionId, { recipient, text = 'WAG Hub test message' }) {
    return this.client.request(`/connections/${connectionId}/test`, {
      method: 'POST',
      body: { recipient, text },
    });
  }
}

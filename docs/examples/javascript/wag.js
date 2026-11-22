// v2 Connection API client. WAG_URL and WAG_TOKEN come from the integration pack.
export function createWag({ url, token }) {
  const base = url.replace(/\/$/, '');

  async function request(path, { method = 'GET', body, idempotencyKey } = {}) {
    const headers = {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    };

    if (body) {
      headers['Content-Type'] = 'application/json';
    }

    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }

    const response = await fetch(`${base}${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await response.json();

    if (!response.ok) {
      const code = json?.error?.code || response.status;
      const error = new Error(`WAG Hub request failed: ${code}`);
      error.retryable = Boolean(json?.error?.retryable);
      throw error;
    }

    return json;
  }

  return {
    // Shortest send: POST /api/v2/messages on the default connection. `to` may be local
    // ("0812-3456-7890"); add { image: url } or { document: url } to send a file by URL.
    // Always 202 with data.status. Retry a timeout with the SAME idempotencyKey.
    sendMessage(to, text, idempotencyKey, options = {}) {
      return request('/api/v2/messages', {
        method: 'POST',
        body: { to, text, ...options },
        idempotencyKey,
      });
    },
    connections: {
      list() {
        return request('/api/v2/connections');
      },
      get(connectionId) {
        return request(`/api/v2/connections/${encodeURIComponent(connectionId)}`);
      },
      // 202 for a managed number (queued on its v2 session), 201 for a provider route.
      // Retry with the SAME idempotencyKey: the original message is returned.
      send(connectionId, to, text, idempotencyKey) {
        return request(`/api/v2/connections/${encodeURIComponent(connectionId)}/messages`, {
          method: 'POST',
          body: { to, type: 'text', text },
          idempotencyKey,
        });
      },
    },
  };
}

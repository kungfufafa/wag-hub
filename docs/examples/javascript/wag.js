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
      throw new Error(`WAG Hub request failed: ${code}`);
    }

    return json;
  }

  return {
    messages: {
      send(connection, recipient, message, idempotencyKey) {
        const payload = { recipient, message };

        if (connection) {
          payload.connection_id = connection;
        }

        return request('/api/v1/messages', {
          method: 'POST',
          body: payload,
          idempotencyKey,
        });
      },
    },
    connections() {
      return request('/api/v1/connections');
    },
  };
}

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

    return response.json();
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

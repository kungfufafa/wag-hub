# WAG Hub Node.js client

Minimal application-facing client for WAG Hub.

## Install

Copy `index.mjs` into your project or reference it from the monorepo:

```bash
npm install ./sdk/node
```

## Configure

```env
WAG_URL=https://gateway.example.com
WAG_TOKEN=your-application-token
WAG_CONNECTION_ID=optional-default-connection-uuid
```

## Usage

```javascript
import { WagClient } from '@wag-hub/client';

const wag = WagClient.fromEnv();

// Send using default connection (WAG_CONNECTION_ID or route_key fallback)
const { data } = await wag.messages().send({
  recipient: '6281234567890',
  text: 'Hello',
  idempotencyKey: 'order-123',
});

// Explicit connection
await wag.messages().send({
  recipient: '6281234567890',
  text: 'Hello',
  idempotencyKey: 'order-124',
  connectionId: process.env.WAG_CONNECTION_ID,
});

// Connection lifecycle
const { data: connections } = await wag.connections().list();
const created = await wag.connections().create({
  name: 'WhatsApp Utama',
  type: 'managed_number',
  isDefault: true,
});
await wag.connections().setup(created.data.id, { mode: 'qr' });
```

Legacy `/api/v1/engine` endpoints remain available for session-level control.

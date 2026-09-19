# WAG Hub Python client

Minimal application-facing client for WAG Hub (Python 3.10+). No external dependencies.

## Configure

```env
WAG_URL=https://gateway.example.com
WAG_TOKEN=your-application-token
WAG_CONNECTION_ID=optional-default-connection-uuid
```

## Usage

```python
from wag_client import WagClient

wag = WagClient.from_env()

result = wag.messages().send(
    recipient="6281234567890",
    text="Hello from WAG Hub",
    idempotency_key="order-123",
)

connections = wag.connections().list()
diag = wag.connections().diagnostics(connections["data"][0]["id"])
```

Copy `wag_client.py` into your project or add `sdk/python` to `PYTHONPATH`.

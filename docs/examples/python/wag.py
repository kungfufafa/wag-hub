"""v2 Connection API client. WAG_URL and WAG_TOKEN come from the integration pack."""

import json
import os
import urllib.error
import urllib.parse
import urllib.request


class Wag:
    def __init__(self, url: str | None = None, token: str | None = None) -> None:
        self.url = (url or os.environ["WAG_URL"]).rstrip("/")
        self.token = token or os.environ["WAG_TOKEN"]

    def send_message(self, to: str, text: str, idempotency_key: str, **media: str) -> dict:
        """Shortest send on the default connection. `to` may be local ("0812-3456-7890").
        Pass image=url or document=url to send a file by URL. Always 202; retry with the SAME key."""
        payload = {"to": to, "text": text, **{k: v for k, v in media.items() if v}}
        return self._request("POST", "/api/v2/messages", payload, idempotency_key)

    def send(self, connection: str, to: str, text: str, idempotency_key: str) -> dict:
        """202 for a managed number, 201 for a provider route. Retry with the SAME key."""
        path = f"/api/v2/connections/{urllib.parse.quote(connection)}/messages"
        return self._request("POST", path, {"to": to, "type": "text", "text": text}, idempotency_key)

    def connections(self) -> dict:
        return self._request("GET", "/api/v2/connections")

    def connection(self, connection: str) -> dict:
        return self._request("GET", f"/api/v2/connections/{urllib.parse.quote(connection)}")

    def _request(self, method: str, path: str, payload: dict | None = None, idempotency_key: str | None = None) -> dict:
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.token}",
        }
        data = None
        if payload is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload).encode()
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        request = urllib.request.Request(self.url + path, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request) as response:
                return json.loads(response.read().decode())
        except urllib.error.HTTPError as error:
            raise RuntimeError(f"WAG Hub request failed: {error.code}") from error

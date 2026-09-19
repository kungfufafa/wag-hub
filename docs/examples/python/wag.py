import json
import os
import urllib.error
import urllib.request


class Wag:
    def __init__(self, url: str | None = None, token: str | None = None) -> None:
        self.url = (url or os.environ["WAG_URL"]).rstrip("/")
        self.token = token or os.environ["WAG_TOKEN"]

    def send(self, recipient: dict, message: dict, idempotency_key: str, connection: str | None = None) -> dict:
        payload = {"recipient": recipient, "message": message}
        if connection:
            payload["connection_id"] = connection
        return self._request("POST", "/api/v1/messages", payload, idempotency_key)

    def connections(self) -> dict:
        return self._request("GET", "/api/v1/connections")

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
                if response.status >= 400:
                    raise RuntimeError(f"WAG Hub request failed: {response.status}")
                return json.loads(response.read().decode())
        except urllib.error.HTTPError as error:
            raise RuntimeError(f"WAG Hub request failed: {error.code}") from error

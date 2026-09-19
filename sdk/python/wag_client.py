"""Minimal WAG Hub client for Python 3.10+."""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any, Mapping, MutableMapping, Optional


class WagError(Exception):
    def __init__(self, message: str, status: int, code: str, payload: Mapping[str, Any]):
        super().__init__(message)
        self.status = status
        self.code = code
        self.payload = payload


@dataclass
class WagClient:
    base_url: str
    token: str
    default_connection_id: Optional[str] = None

    @classmethod
    def from_env(cls, env: Optional[Mapping[str, str]] = None) -> "WagClient":
        env = env or os.environ
        return cls(
            base_url=(env.get("WAG_URL") or "").rstrip("/"),
            token=env.get("WAG_TOKEN") or "",
            default_connection_id=env.get("WAG_CONNECTION_ID"),
        )

    def messages(self) -> "WagMessagesClient":
        return WagMessagesClient(self)

    def connections(self) -> "WagConnectionsClient":
        return WagConnectionsClient(self)

    def request(
        self,
        path: str,
        *,
        method: str = "GET",
        body: Optional[Mapping[str, Any]] = None,
        idempotency_key: Optional[str] = None,
        headers: Optional[MutableMapping[str, str]] = None,
    ) -> dict[str, Any]:
        if not self.base_url or not self.token:
            raise ValueError("WAG Hub client requires base_url and token.")

        request_headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.token}",
        }

        if headers:
            request_headers.update(headers)

        data = None
        if body is not None:
            request_headers["Content-Type"] = "application/json"
            data = json.dumps(body).encode("utf-8")

        if idempotency_key:
            request_headers["Idempotency-Key"] = idempotency_key

        request = urllib.request.Request(
            f"{self.base_url}/api/v1{path}",
            data=data,
            headers=request_headers,
            method=method,
        )

        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            payload = json.loads(error.read().decode("utf-8") or "{}")
            raise WagError(
                payload.get("message", f"WAG Hub request failed ({error.code})"),
                error.code,
                (payload.get("error") or {}).get("code", "request_failed"),
                payload,
            ) from error


@dataclass
class WagMessagesClient:
    client: WagClient

    def send(
        self,
        *,
        recipient: str,
        text: str,
        idempotency_key: str,
        connection_id: Optional[str] = None,
        purpose: str = "notification",
        mode: str = "sync",
        use_default_connection: bool = False,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {
            "recipient": {"type": "phone", "value": recipient},
            "message": {"type": "text", "text": text},
            "purpose": purpose,
            "mode": mode,
        }

        resolved_connection = connection_id or self.client.default_connection_id
        extra_headers: dict[str, str] = {}

        if resolved_connection:
            body["connection_id"] = resolved_connection
        elif not use_default_connection:
            body["route_key"] = "default"
        else:
            extra_headers["X-WAG-Use-Default-Connection"] = "true"

        return self.client.request(
            "/messages",
            method="POST",
            body=body,
            idempotency_key=idempotency_key,
            headers=extra_headers,
        )

    def get(self, message_id: str) -> dict[str, Any]:
        return self.client.request(f"/messages/{message_id}")


@dataclass
class WagConnectionsClient:
    client: WagClient

    def list(self) -> dict[str, Any]:
        return self.client.request("/connections")

    def get(self, connection_id: str) -> dict[str, Any]:
        return self.client.request(f"/connections/{connection_id}")

    def diagnostics(self, connection_id: str) -> dict[str, Any]:
        return self.client.request(f"/connections/{connection_id}/diagnostics")

    def create(
        self,
        *,
        name: str,
        type: str,
        driver: Optional[str] = None,
        configuration: Optional[Mapping[str, Any]] = None,
        is_default: bool = False,
    ) -> dict[str, Any]:
        return self.client.request(
            "/connections",
            method="POST",
            body={
                "name": name,
                "type": type,
                "driver": driver,
                "configuration": configuration,
                "is_default": is_default,
            },
        )

    def setup(self, connection_id: str, *, mode: str = "qr", phone: Optional[str] = None) -> dict[str, Any]:
        return self.client.request(
            f"/connections/{connection_id}/setup",
            method="POST",
            body={"mode": mode, "phone": phone},
        )

    def test(self, connection_id: str, *, recipient: str, text: str = "WAG Hub test message") -> dict[str, Any]:
        return self.client.request(
            f"/connections/{connection_id}/test",
            method="POST",
            body={"recipient": recipient, "text": text},
        )

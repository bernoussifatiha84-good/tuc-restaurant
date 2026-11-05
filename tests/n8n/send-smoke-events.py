#!/usr/bin/env python3
"""Simule un fournisseur WhatsApp vers un workflow n8n éphémère, sans réseau externe."""

import hashlib
import hmac
import json
import os
import time
import urllib.error
import urllib.request

BASE_URL = os.environ.get("TUC_N8N_URL", "http://127.0.0.1:5678").rstrip("/")
INBOUND_SECRET = os.environ["TUC_N8N_INBOUND_SECRET"]
# Probe both URL shapes found across n8n Webhook node versions: a UUID-qualified
# URL and the configured path-only production URL.
WEBHOOKS = (
    BASE_URL + "/webhook/7b75d76f-f3a6-4579-bfc0-8a6e9561fd74/tuc-whatsapp-inbound-smoke",
    BASE_URL + "/webhook/tuc-whatsapp-inbound-smoke",
)


def send_event(event_id: str, code: str, phone: str, *, timestamp: int | None = None, corrupt: bool = False):
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    normalized_phone = "".join(ch for ch in phone if ch.isdigit())
    canonical = "\n".join((stamp, event_id, code.upper(), normalized_phone)).encode()
    signature = hmac.new(INBOUND_SECRET.encode(), canonical, hashlib.sha256).hexdigest()
    if corrupt:
        signature = "0" * 64

    body = json.dumps({"event_id": event_id, "code": code, "tel": phone}).encode()
    last_result = (404, "")
    for attempt in range(20):
        for webhook_url in WEBHOOKS:
            request = urllib.request.Request(
                webhook_url,
                data=body,
                headers={
                    "Content-Type": "application/json",
                    "X-TUC-Timestamp": stamp,
                    "X-TUC-Signature": signature,
                },
                method="POST",
            )
            try:
                with urllib.request.urlopen(request, timeout=30) as response:
                    return response.status, response.read().decode()
            except urllib.error.HTTPError as error:
                last_result = (error.code, error.read().decode())
                # Only retry an unregistered Express route; never replay an event
                # once n8n has accepted it and returned an application response.
                if error.code != 404 or "Cannot POST" not in last_result[1]:
                    return last_result
        if attempt < 19:
            time.sleep(1)
    return last_result


def main() -> None:
    status, body = send_event("e2e-event-success-01", "N8NTEST1", "+212600000099")
    try:
        result = json.loads(body)
    except json.JSONDecodeError as error:
        raise AssertionError(
            f"n8n returned HTTP {status} with a non-JSON response ({len(body)} bytes): {body[:200]!r}"
        ) from error
    assert status == 200, f"n8n success path failed with HTTP {status}: {body[:200]!r}"
    assert result.get("ok") is True and "Connexion directe" in result.get("reponse", ""), "la réponse WordPress n’a pas confirmé la création du compte"
    print("OK n8n: signature valide transmise et réponse WordPress reçue")

    # Même event_id avec un autre code et un nouveau timestamp doit être arrêté
    # par la protection anti-rejeu de WordPress, pas réussir une seconde fois.
    status, body = send_event("e2e-event-success-01", "N8NTEST2", "+212600000098")
    assert status >= 400 and '"ok":true' not in body.replace(" ", ""), (
        f"le rejeu d'event_id a été accepté: HTTP {status}"
    )
    print("OK n8n: rejeu event_id refusé en aval")

    status, body = send_event("e2e-event-bad-signature", "N8NTEST3", "+212600000097", corrupt=True)
    assert status >= 400 and '"ok":true' not in body.replace(" ", ""), (
        f"la signature entrante incorrecte a été acceptée: HTTP {status}"
    )
    print("OK n8n: signature entrante incorrecte refusée")

    status, body = send_event(
        "e2e-event-stale-signature",
        "N8NTEST4",
        "+212600000096",
        timestamp=int(time.time()) - 600,
    )
    assert status >= 400 and '"ok":true' not in body.replace(" ", ""), (
        f"un événement expiré a été accepté: HTTP {status}"
    )
    print("OK n8n: événement signé expiré refusé")


if __name__ == "__main__":
    main()

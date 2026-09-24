# Event Webhooks (`local_webhookengine`)

Event Webhooks is a high-performance, enterprise-grade generic webhook dispatcher for Moodle 4.5+. It captures any Moodle event (Quiz completions, Course completions, User enrolments, Assignment submissions, Badges, Forum discussions, and more) and reliably delivers cryptographically signed JSON payloads to any HTTPS destination, including n8n, Zapier, Make, custom APIs, and CRMs.

---

## 1. Features

- **Any Moodle Event:** Subscribe to any core or plugin event directly from the admin UI.
- **Asynchronous & Non-Blocking:** 0 DB queries on non-matching events; matching events are committed to a resilient queue and dispatched in the background. Students and teachers never experience request delays.
- **Standard Webhooks Compatible:** Complies with the [Standard Webhooks specification](https://www.standardwebhooks.com/) (`webhook-id`, `webhook-timestamp`, `webhook-signature` with HMAC-SHA256).
- **Flexible Scope & Filters:** Filter events by Course IDs, Course Category hierarchy (with subcategory support), or exclude automated/guest activity.
- **Payload Customization:** Opt in to include user details, email addresses, course metadata, and raw event `other` data. Optionally supply a custom JSON template with safe parameter replacement.
- **Enterprise Reliability:** Automatic retries with exponential backoff (1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h) and jitter.
- **Health Monitoring & Auto-Pause:** Proactively monitors consecutive failures, automatically pauses dead endpoints to conserve cron resources, and notifies administrators via Moodle messages.
- **Security & Privacy First:** Secrets and custom headers are encrypted at rest using Moodle's encryption subsystem (`\core\encryption`). Full Moodle Privacy API implementation.

---

## 2. Requirements

- **Moodle:** 4.5 LTS (Build: 2024100700) to 5.2 (and 5.3 LTS).
- **PHP:** 8.1, 8.2, or 8.3 (as supported by your Moodle version).
- **Database:** PostgreSQL or MariaDB / MySQL.
- **Moodle Cron:** Must be configured to execute every minute.

---

## 3. Installation

### Method A: Admin ZIP Upload
1. Log in to your Moodle site as an Administrator.
2. Navigate to **Site administration > Plugins > Install plugins**.
3. Upload `local_webhookengine_1.0.0.zip` and follow the on-screen upgrade prompts.

### Method B: Manual Git / Directory Extraction
1. Extract the `webhookengine` folder into your Moodle installation's `local/` directory:
   ```bash
   # Destination directory:
   <moodle-root>/local/webhookengine
   ```
2. Visit **Site administration > Notifications** to complete the database schema installation.

---

## 4. Standard Payload Structure (Schema 1)

When an event occurs, Webhook Engine dispatches a JSON envelope formatted as follows:

```json
{
  "id": "6f1c0b1e-4c72-4d7a-9a99-b1d3a4362a21",
  "schema": 1,
  "type": "mod_quiz.attempt_submitted",
  "eventname": "\\mod_quiz\\event\\attempt_submitted",
  "created": 1757682400,
  "site": {
    "url": "https://moodle.example.edu"
  },
  "event": {
    "component": "mod_quiz",
    "action": "submitted",
    "target": "attempt",
    "objectid": 1420,
    "crud": "u",
    "edulevel": 2,
    "contextid": 55,
    "contextlevel": 70,
    "contextinstanceid": 12,
    "courseid": 4,
    "userid": 892,
    "relateduserid": 892,
    "timecreated": 1757682400
  }
}
```

### Opt-in Enriched Fields
- **User details (`user` / `relateduser`):** `{ "id": 892, "username": "jdoe", "firstname": "Jane", "lastname": "Doe" }`
- **User email:** Added to user objects when explicitly enabled: `"email": "jane.doe@example.edu"`.
- **Course details (`course`):** `{ "id": 4, "shortname": "BIO101", "fullname": "Introduction to Biology", "categoryid": 2 }`
- **Event Other metadata (`event.other`):** Raw key-value metadata passed by the triggering event.

---

## 5. Webhook Signature Verification

Every outbound webhook includes the following Standard Webhooks HTTP headers:
- `webhook-id`: Unique delivery UUID (e.g. `6f1c0b1e-4c72-4d7a-9a99-b1d3a4362a21`).
- `webhook-timestamp`: Unix epoch seconds when the request was signed.
- `webhook-signature`: `v1,<base64-hmac-sha256>`.

The signature is computed over:
```
{webhook-id}.{webhook-timestamp}.{raw-request-body}
```
Using the signing secret (raw bytes of the base64 secret presented upon creation).

### Python Receiver Example (FastAPI / Flask)

```python
import base64
import hashlib
import hmac
import time
from fastapi import FastAPI, Header, HTTPException, Request

app = FastAPI()

# Your webhook secret without the 'whsec_' prefix:
WEBHOOK_SECRET = "whsec_YOUR_BASE64_SECRET"

def verify_signature(raw_body: bytes, msg_id: str, timestamp_str: str, signature_header: str) -> bool:
    try:
        # Check timestamp freshness (tolerance: 5 minutes = 300 seconds)
        timestamp = int(timestamp_str)
        if abs(time.time() - timestamp) > 300:
            return False

        secret_bytes = base64.b64decode(WEBHOOK_SECRET.replace("whsec_", ""))
        to_sign = f"{msg_id}.{timestamp_str}.".encode("utf-8") + raw_body
        expected_sig = base64.b64encode(hmac.new(secret_bytes, to_sign, hashlib.sha256).digest()).decode("utf-8")

        for sig in signature_header.split(" "):
            if sig.startswith("v1,"):
                received_sig = sig.split(",", 1)[1]
                if hmac.compare_digest(expected_sig, received_sig):
                    return True
    except Exception:
        return False
    return False

@app.post("/webhook")
async def handle_webhook(
    request: Request,
    webhook_id: str = Header(..., alias="webhook-id"),
    webhook_timestamp: str = Header(..., alias="webhook-timestamp"),
    webhook_signature: str = Header(..., alias="webhook-signature")
):
    body = await request.body()
    if not verify_signature(body, webhook_id, webhook_timestamp, webhook_signature):
        raise HTTPException(status_code=401, detail="Invalid webhook signature")

    payload = await request.json()
    print(f"Received verified event: {payload['type']} (ID: {webhook_id})")
    return {"status": "ok"}
```

### Node.js / Express Receiver Example

```javascript
const express = require('express');
const crypto = require('crypto');

const app = express();
app.use(express.raw({ type: 'application/json' }));

const SECRET = 'whsec_YOUR_BASE64_SECRET';

function verifyWebhook(rawBody, id, timestamp, signatureHeader) {
    if (Math.abs(Math.floor(Date.now() / 1000) - parseInt(timestamp, 10)) > 300) {
        return false;
    }

    const secretBytes = Buffer.from(SECRET.replace(/^whsec_/, ''), 'base64');
    const toSign = Buffer.concat([
        Buffer.from(`${id}.${timestamp}.`, 'utf8'),
        rawBody
    ]);

    const expectedSig = crypto.createHmac('sha256', secretBytes).update(toSign).digest('base64');
    const signatures = signatureHeader.split(' ');

    for (const sig of signatures) {
        if (sig.startsWith('v1,')) {
            const receivedSig = sig.slice(3);
            if (crypto.timingSafeEqual(Buffer.from(expectedSig), Buffer.from(receivedSig))) {
                return true;
            }
        }
    }
    return false;
}

app.post('/webhook', (req, res) => {
    const id = req.headers['webhook-id'];
    const timestamp = req.headers['webhook-timestamp'];
    const signature = req.headers['webhook-signature'];

    if (!verifyWebhook(req.body, id, timestamp, signature)) {
        return res.status(401).send('Invalid signature');
    }

    const payload = JSON.parse(req.body.toString('utf8'));
    console.log(`Verified webhook ${payload.type}`);
    res.status(200).json({ received: true });
});

app.listen(3000, () => console.log('Listening on port 3000'));
```

### PHP Receiver Example

```php
<?php
$rawbody = file_get_contents('php://input');
$id = $_SERVER['HTTP_WEBHOOK_ID'] ?? '';
$timestamp = $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '';
$sigheader = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';
$secret = 'whsec_YOUR_BASE64_SECRET';

if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(401);
    die('Timestamp expired');
}

$secretbytes = base64_decode(str_replace('whsec_', '', $secret));
$tosign = $id . '.' . $timestamp . '.' . $rawbody;
$expectedsig = base64_encode(hash_hmac('sha256', $tosign, $secretbytes, true));

$valid = false;
foreach (explode(' ', $sigheader) as $sig) {
    if (str_starts_with($sig, 'v1,')) {
        if (hash_equals($expectedsig, substr($sig, 3))) {
            $valid = true;
            break;
        }
    }
}

if (!$valid) {
    http_response_code(401);
    die('Invalid signature');
}

$payload = json_decode($rawbody, true);
// Process payload...
http_response_code(200);
echo json_encode(['received' => true]);
```

---

## 6. Security Architecture

1. **Strict HTTPS & SSRF Mitigation:** All outbound URLs are strictly validated using Moodle core's `\core\files\curl_security_helper`. Insecure HTTP, private intranet IP loops, and URL embedded credentials (`user:pass@host`) are blocked.
2. **Encrypted at Rest:** Signing secrets and custom authentication header tokens are encrypted in the Moodle database using `\core\encryption`.
3. **Loop Protection:** Internal audit events generated by `local_webhookengine` are excluded from triggering new webhooks.
4. **Anonymous Event Scrubbing:** Moodle events marked as anonymous (e.g. anonymous surveys or feedback) have all actor user IDs stripped prior to queueing.
5. **No Follow Redirects:** Outbound HTTP requests refuse 3xx redirects to prevent redirect-based SSRF.

---

## 7. Delivery Guarantees & Retry Schedule

- **Guarantee:** At-least-once delivery. (Destinations must de-duplicate on `webhook-id`.)
- **Retry Backoff Schedule:**
  1. 1 minute
  2. 5 minutes
  3. 15 minutes
  4. 1 hour
  5. 3 hours
  6. 6 hours
  7. 12 hours
  8. 24 hours
  (Each retry includes a random ±10% jitter to prevent thundering herds.)
- **HTTP Status Codes:**
  - `2xx`: Success (delivery payload cleared).
  - `408`, `425`, `429`, `5xx`, connection timeout: Retryable.
  - `400`, `401`, `403`, `404`, `410`, `422`, `3xx`: Terminal failure (no automatic retry; can be replayed from UI).

---

## 8. License

This plugin is licensed under the [GNU General Public License v3 or later](LICENSE).
© 2026 Definite Labs.

---

## 9. Bug Tracker & Support

Please report any bugs or feature requests on our public issue tracker:
[https://github.com/definitelabs/moodle-local_webhookengine/issues](https://github.com/definitelabs/moodle-local_webhookengine/issues)


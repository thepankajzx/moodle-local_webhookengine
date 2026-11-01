# Security Policy

Definite Labs takes the security and integrity of its Moodle plugins seriously. We follow industry best practices, OWASP guidelines, and responsible disclosure standards to ensure institutional security for universities, government-linked entities, and enterprise LMS deployments.

---

## 1. Supported Versions

We actively provide security patches, vulnerability mitigations, and compliance updates for the following versions of `local_webhookengine`:

| Version | Supported | Moodle Compatibility | Status |
|:---:|:---:|:---:|:---|
| **1.1.0** | :white_check_mark: | 4.5 LTS – 5.2 | **Active / Current Stable Release** (Hardened) |
| **1.0.3** | :white_check_mark: | 4.5 LTS – 5.2 | Maintained (Security patches) |
| 1.0.2 | :x: | 4.5 – 5.2 | End of Life (Upgrade to 1.1.0 immediately) |
| 1.0.1 | :x: | 4.5 – 5.2 | End of Life |
| 1.0.0 | :x: | 4.5 – 5.2 | End of Life |

If you are running any version prior to **v1.0.3**, please upgrade immediately to benefit from critical security fixes (including SSRF network policy isolation, template fail-closed protection, and single-use secret reveal tokens).

---

## 2. Reporting a Vulnerability

If you discover or suspect a security vulnerability in `local_webhookengine`, please report it responsibly directly to our security response team:

* **Primary Security Contact:** `support@thedefinite.one`
* **Alternate Reporting:** [GitHub Private Security Advisory](https://github.com/thepankajzx/moodle-local_webhookengine/security/advisories/new)

> [!IMPORTANT]
> **Please do NOT open public GitHub issues or forum threads for suspected security vulnerabilities.** All security reports should remain confidential until a coordinated disclosure and patch release are finalized.

### What to Include in Your Report:
To help us triage and resolve the issue quickly, please provide:
1. Exact plugin version and Moodle environment details (Moodle version, PHP version, database engine).
2. Type of vulnerability (e.g. SSRF, Access Control, Injection, Replay, Cryptographic bypass).
3. Clear, step-by-step reproduction instructions or a minimal proof-of-concept (PoC).
4. Potential business or security impact assessment.

---

## 3. Vulnerability Response Timelines & SLA

We adhere to the following SLA commitments for all verified security reports:

- **Initial Acknowledgement:** Within **3 business days** of report receipt.
- **Triage & Severity Assessment:** Within **5 business days**.
- **Fix Delivery:** As soon as possible, with a target of **14 days** for critical severity issues.
- **Public Disclosure:** Coordinated release and advisory publication after users have been provided with patched release packages.

---

## 4. Safe Harbor Policy

Definite Labs supports ethical security research conducted in good faith. If you conduct vulnerability research in accordance with the following principles, we will not pursue civil or criminal legal action against you:

- You make a good-faith effort to avoid privacy violations, data destruction, service degradation, or denial-of-service against live production environments.
- You perform testing exclusively on your own LMS instances or local development sandboxes.
- You do not exfiltrate, modify, or retain sensitive user data (PII, student grades, authentication tokens).
- You give our team a reasonable opportunity to remediate the vulnerability before public disclosure.

---

## 5. Security Architecture Highlights (v1.0.3+)

- **SSRF Hardening:** Strict pre-flight DNS resolution, anti-private-range verification across all resolved addresses, and connection pinning via `CURLOPT_RESOLVE` to defeat DNS rebinding attacks.
- **Fail-Closed Execution:** Custom template rendering fails closed with zero network dispatch on invalid syntax.
- **Strict Capability Controls:** Webhook administration, log inspection, and replay restricted by default exclusively to Site Administrators (`moodle/site:config`).
- **Cryptographic Non-Repudiation:** SHA-256 payload `bodyhash` recorded at dispatch time and retained permanently after GDPR payload cleanup.
- **Encrypted Credentials:** Signing secrets and custom authentication headers are encrypted at rest using Moodle's sodium-backed core encryption subsystem (`\core\encryption`).

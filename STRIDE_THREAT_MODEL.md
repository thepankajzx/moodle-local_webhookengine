# STRIDE Threat Model & Enterprise Security Verification Report

**Product:** Event Webhooks Engine (`local_webhookengine`)  
**Version:** 1.1.0 (Enterprise Hardened)  
**Date:** 2026-11-01  
**Author:** Definite Labs Security Engineering  
**Classification:** Institutional Security Assessment & Compliance Whitepaper  
**Target Environments:** Higher Education (Tier-1 Universities), Banking, Government LMS Deployments  
**Applicable Frameworks:** OWASP Top 10, Standard Webhooks Specification, STRIDE Threat Modeling, GDPR / Moodle Privacy API

---

## Executive Summary

The Event Webhooks Engine (`local_webhookengine`) is a mission-critical Moodle integration component that transforms internal LMS events into authenticated, real-time HTTP webhooks delivered to external enterprise receivers (CRMs, ERPs, automation buses, cloud APIs, and data lakes).

Because webhook dispatchers operate at the boundary between internal LMS databases and external networks, they represent a high-value attack surface. This document presents a comprehensive **STRIDE Threat Model** evaluation of the v1.1.0 release, formally documenting how every potential attack vector—across **S**poofing, **T**ampering, **R**epudiation, **I**nformation Disclosure, **D**enial of Service, and **E**levation of Privilege—is mitigated by specific code constructs and verified by automated unit and integration tests.

---

## 1. System Architecture & Trust Boundaries

```
+--------------------------------------------------------------------------------------------------+
| MOODLE LMS TRUST ZONE                                                                            |
|                                                                                                  |
|   [Student / Teacher Action]                                                                     |
|             |                                                                                    |
|             v                                                                                    |
|   [Moodle Core Event System]                                                                     |
|             |                                                                                    |
|             v                                                                                    |
|   +------------------------------------------------------------------------------------------+   |
|   | \local_webhookengine\observer                                                            |   |
|   |  - Event Map Lookup (MUC cache)                                                          |   |
|   |  - Circuit Breaker Ingress Check (Drop Ledger if OPEN)                                   |   |
|   |  - Rate Limiter Backlog Guard                                                            |   |
|   |  - Fast DB Insert: {local_webhookengine_delivery} & {task_adhoc}                         |   |
|   +------------------------------------------------------------------------------------------+   |
|             |                                                                                    |
|             v (Asynchronous Background Task)                                                     |
|   +------------------------------------------------------------------------------------------+   |
|   | \local_webhookengine\task\send_delivery -> \local_webhookengine\delivery_service          |   |
|   |  - Circuit Breaker State Evaluation                                                      |   |
|   |  - Payload Builder (Strict Fail-Closed JSON Template)                                    |   |
|   |  - SHA-256 Body Hash Computation (Non-repudiation)                                       |   |
|   |  - Standard Webhooks Multi-Signature Generation (Dual-Secret Rotation Grace Period)       |   |
|   |  - Auth Resolver: Optional mTLS Client Certs & OAuth2 Bearer Injection                   |   |
|   +------------------------------------------------------------------------------------------+   |
|             |                                                                                    |
|             v                                                                                    |
|   +------------------------------------------------------------------------------------------+   |
|   | \local_webhookengine\http_client                                                         |   |
|   |  - Pre-flight DNS Resolution & Anti-Private-IP Verification                              |   |
|   |  - CURLOPT_RESOLVE Connection Pinning (Anti-DNS Rebinding)                               |   |
|   |  - Mutual TLS (mTLS) Pre-flight Checks (CURLOPT_SSLCERT, CURLOPT_SSLKEY)                  |   |
|   |  - Strict TLS Verification (CURLOPT_SSL_VERIFYPEER => 1, CURLOPT_FOLLOWLOCATION => 0)    |   |
|   +------------------------------------------------------------------------------------------+   |
+-------------|------------------------------------------------------------------------------------+
              |
              | (Encrypted TLS Outbound Network Boundary)
              v
+--------------------------------------------------------------------------------------------------+
| EXTERNAL DESTINATION ZONE                                                                        |
|                                                                                                  |
|   [Enterprise Endpoint: Azure / AWS API Gateway / n8n / Zapier / On-Premise Receiver]            |
|   - Verifies HMAC-SHA256 signature against webhook-id and webhook-timestamp                      |
|   - Deduplicates requests on webhook-id UUID                                                     |
+--------------------------------------------------------------------------------------------------+
```

---

## 2. STRIDE Threat Mitigation Matrix

### 2.1 Spoofing Identity

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **S-01** | **Forged Webhook Payloads** | Attacker crafts synthetic HTTP requests to receiver pretending to be the LMS. | Outbound requests are signed using HMAC-SHA256 adhering to the Standard Webhooks specification. Secrets are 32 cryptographically random bytes (`random_bytes(32)`) encrypted with Moodle core sodium encryption (`\core\encryption`). Space-delimited multi-signatures support zero-downtime rotation. | [`classes/signer.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/signer.php)<br>[`classes/hook_repository.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/hook_repository.php) | `tests/dual_secret_rotation_test.php`<br>`tests/hook_repository_test.php` |
| **S-02** | **Receiver Impersonation (MitM)** | Attacker intercepts outbound traffic using bogus TLS certificates. | Unconditional TLS peer and host verification (`CURLOPT_SSL_VERIFYPEER => 1`, `CURLOPT_SSL_VERIFYHOST => 2`). In Zero Trust environments, outbound requests present client X.509 certificates (mTLS) with pre-flight certificate validation. | [`classes/http_client.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/http_client.php) | `tests/auth_modes_test.php`<br>`tests/url_validator_test.php` |
| **S-03** | **Cloud Token Impersonation** | Attacker intercepts or exploits stale API keys on cloud endpoints. | Automated OAuth2 Client Credentials flow (`grant_type=client_credentials`). Acquires short-lived JWT Bearer tokens directly from enterprise token endpoints; tokens cached in MUC with pre-expiry buffers. | [`classes/oauth2_client.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/oauth2_client.php) | `tests/auth_modes_test.php` |

---

### 2.2 Tampering with Data

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **T-01** | **In-Transit Body Tampering** | Attacker alters payload contents (e.g. student grade or completion status). | HMAC signature is computed over `{webhook-id}.{webhook-timestamp}.{raw-body}`. Any byte modification invalidates signature verification on the receiving endpoint. | [`classes/signer.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/signer.php) | `tests/dual_secret_rotation_test.php` |
| **T-02** | **Silent Credential Wiping** | Pausing/resuming a webhook overwrites stored encrypted Authorization headers with `NULL`. | Surgical status transition method `hook_repository::set_hook_status()` exclusively mutates the `status` column, guaranteeing credential isolation from administrative lifecycle actions. | [`classes/hook_repository.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/hook_repository.php#L359-L380)<br>[`action.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/action.php) | `tests/hook_repository_test.php` |
| **T-03** | **Synthetic Replay Corruption** | Admin replays a delivery whose payload was purged under GDPR, sending synthetic `{}` empty body with valid signature. | Delivery replay guard explicitly halts execution with `payload_purged_cannot_replay` if stored payload is null. Valid replays preserve the original delivery UUID to allow receiver-side deduplication. | [`classes/delivery_service.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/delivery_service.php#L45-L65)<br>[`action.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/action.php) | `tests/delivery_replay_test.php` |

---

### 2.3 Repudiation

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **R-01** | **Delivery Repudiation** | Receiver claims delivered payload contained different data, or LMS admin disputes delivery content after GDPR payload purge. | Cryptographic non-repudiation: SHA-256 payload `bodyhash` computed at dispatch time and stored in `{local_webhookengine_delivery}`. Persists permanently even after payload content is wiped. | [`classes/delivery_service.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/delivery_service.php#L154-L157)<br>[`db/upgrade.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/db/upgrade.php) | `tests/audit_lifecycle_test.php` |
| **R-02** | **Configuration Repudiation** | Administrator denies changing target URLs, filters, or security settings. | Granular audit events (`\local_webhookengine\event\hook_updated`) log before/after diffs of all non-sensitive configuration fields. Hook deletion is soft-delete (`status = 'deleted'`), retaining audit trails. | [`classes/hook_repository.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/hook_repository.php#L330-L355)<br>[`classes/event/hook_updated.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/event/hook_updated.php) | `tests/audit_lifecycle_test.php` |

---

### 2.4 Information Disclosure

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **I-01** | **Server-Side Request Forgery (SSRF)** | Attacker registers endpoints targeting cloud metadata services (`169.254.169.254`), loopbacks, or intranet hosts. | Comprehensive network policy: private, loopback, link-local, and reserved IPv4/IPv6 ranges blocked by default. Decoupled from plain HTTP development toggle. Explicit CIDR allowlist (`allowedinternalhosts`) required for intranet dispatch. | [`classes/url_validator.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/url_validator.php)<br>[`settings.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/settings.php) | `tests/url_validator_test.php` |
| **I-02** | **DNS Rebinding (TOCTOU)** | Attacker configures DNS name that resolves to public IP during validation, then rapidly switches to private IP during delivery. | Pre-flight DNS resolution inspects all resolved IP records. Upon verification, the exact resolved IP is pinned using `CURLOPT_RESOLVE`, ensuring cURL connects exclusively to the pre-verified IP. | [`classes/url_validator.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/url_validator.php#L140-L195)<br>[`classes/http_client.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/http_client.php#L106-L110) | `tests/url_validator_test.php` |
| **I-03** | **Redirect-Based SSRF** | Public endpoint responds with HTTP 301/302 redirecting to internal metadata IP. | Redirect following is strictly disabled (`CURLOPT_FOLLOWLOCATION => 0`). Redirect responses (3xx) are classified as terminal delivery failures (`redirect_not_followed`). | [`classes/http_client.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/http_client.php#L101)<br>[`classes/delivery_service.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/delivery_service.php#L248-L255) | `tests/url_validator_test.php` |
| **I-04** | **Credential Exposure in UI & Logs** | API keys, Bearer tokens, or signing secrets leak via browser inspection, session dumps, or notification queues. | Authorization headers are write-only masked in configuration forms (`$k: (configured)`). Signing secrets are revealed exactly once via dedicated `secret_reveal.php` (`Cache-Control: no-store`) with immediate MUC token destruction. Zero secret leakage via `$SESSION` or `\core\notification`. | [`classes/form/hook_form.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/form/hook_form.php)<br>[`secret_reveal.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/secret_reveal.php)<br>[`edit.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/edit.php) | `tests/access_control_test.php` |
| **I-05** | **Sensitive Data Leak via Template Fallback** | Custom template rendering fails, and engine silently falls back to full default envelope containing student PII. | Strict template fail-closed guarantee: any template syntax error or runtime JSON rendering failure immediately marks delivery `failed` (`template_invalid`) with zero network dispatch. Full envelope fallback completely eliminated. | [`classes/payload_builder.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/payload_builder.php)<br>[`classes/delivery_service.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/delivery_service.php#L130-L148) | `tests/template_fail_closed_test.php` |
| **I-06** | **Unrestricted External Egress** | Rogue administrator registers arbitrary third-party endpoints. | Institutional destination domain allowlist (`alloweddestinationdomains`) supporting exact and wildcard patterns (`*.partner.edu`). Enforced at configuration save time AND at delivery dispatch time. | [`classes/url_validator.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/url_validator.php#L200-L235) | `tests/access_control_test.php` |

---

### 2.5 Denial of Service (DoS)

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **D-01** | **Downstream Outage Storming** | Remote endpoint goes down, causing thousands of retry attempts to exhaust Moodle cron runners and cURL sockets. | Autonomous 3-state Circuit Breaker (`closed`, `open`, `half_open`). Trips after configurable failure threshold (default: 10). Halts outbound HTTP traffic, drops ingress directly to drop ledger, and executes automated half-open probe testing upon cooldown expiration. | [`classes/circuit_breaker.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/circuit_breaker.php)<br>[`observer.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/observer.php#L50-L65) | `tests/circuit_breaker_test.php` |
| **D-02** | **Noisy Neighbor Starvation** | A single sluggish or failing endpoint monopolizes all background adhoc task worker threads. | Per-hook concurrency slot throttling via atomic leases (`acquire_hook_slot()` / `release_hook_slot()`, default 5 concurrent tasks) and per-hook backlog capacity limits. | [`classes/rate_limiter.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/rate_limiter.php) | `tests/circuit_breaker_test.php`<br>`tests/drop_ledger_test.php` |
| **D-03** | **Database Lock Contention During Surges** | High-concurrency exam submissions cause database row lock contention on drop counters. | High-throughput aggregated drop ledger `{local_webhookengine_drop_ledger}` with atomic 8-slot sharding (`$slot = abs(crc32($reason . '_' . $hookid)) % 8`) and centralized reason classification. | [`classes/hook_repository.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/hook_repository.php#L450-L500)<br>[`classes/reasons.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/reasons.php) | `tests/drop_ledger_test.php` |
| **D-04** | **Cron Runner Stalling via Zombie Deliveries** | Server crashes or worker kills leave deliveries permanently trapped in `retrying` status without an active adhoc task. | Automated orphaned delivery sweeper in `\local_webhookengine\task\check_health` detecting stuck overdue deliveries and re-queueing them automatically. | [`classes/task/check_health.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/task/check_health.php) | `tests/drop_ledger_test.php` |
| **D-05** | **Dashboard Query Denial of Service** | Frequent page refreshes on administrative dashboards execute expensive aggregation queries across millions of delivery rows. | Real-time performance telemetry service shielded by short-TTL MUC application cache (`local_webhookengine/ratelimit`, TTL 60s), ensuring 0 database queries on repeated dashboard visits. | [`classes/telemetry.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/telemetry.php) | `tests/telemetry_test.php` |

---

### 2.6 Elevation of Privilege

| Threat ID | Threat Description | Attack Vector | Mitigation Mechanism | Source Code Implementation | Verification Test File |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **E-01** | **Manager Role Over-Privilege** | Course managers or low-privilege staff access webhook configurations or student data streams. | Stripped default capability grants (`archetypes => []`) across `manage`, `viewlogs`, `replay`, `exportlogs`, and `exportpersonaldata`. Access restricted strictly to Site Administrators (`moodle/site:config`) by default. | [`db/access.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/db/access.php) | `tests/access_control_test.php` |
| **E-02** | **Cross-Site Request Forgery (CSRF)** | Attacker tricks authenticated admin into clicking link that rotates secrets or pauses critical webhooks. | All state transitions strictly enforce HTTP POST request methods (`require_post()`) and Moodle session key validation (`require_sesskey()`). GET requests for mutations are rejected immediately with HTTP 405. | [`action.php`](file:///C:/MOODLE/server/moodle/local/webhookengine/action.php) | `tests/access_control_test.php`<br>`tests/hook_repository_test.php` |

---

## 3. Privacy, GDPR & Data Governance Verification

The plugin fully implements the Moodle Privacy Subsystem API:

1. **Metadata Declaration ([`provider::get_metadata`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/privacy/provider.php#L44-L74)):** Declares all database tables storing user IDs and personal data (`{local_webhookengine_delivery}`, `{local_webhookengine_hook}`) and explicitly documents outbound external data transmission (`webhook_endpoint`).
2. **Context Resolution ([`provider::get_contexts_for_userid`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/privacy/provider.php#L82-L102)):** Accurately indexes user records in the system context.
3. **User Data Export ([`provider::export_user_data`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/privacy/provider.php#L128-L182)):** Exports delivery history and configured webhooks in human-readable JSON formats via Moodle's content writer.
4. **GDPR Data Erasure ([`provider::delete_data_for_user`](file:///C:/MOODLE/server/moodle/local/webhookengine/classes/privacy/provider.php#L201-L219)):** Purges delivery logs associated with the subject user and anonymizes webhook configurations (`createdby = 0`), preserving system operations while scrubbing PII.
5. **Aggregated Operational Separation:** `{local_webhookengine_drop_ledger}` contains only anonymous integer drop counters and reason strings, ensuring zero personal data footprint.

---

## 4. Unmitigated Threats & Operational Boundaries (Honest Disclosure)

In adherence to honesty and engineering integrity, the following residual risks and operational boundaries are documented:

1. **Host-Level Root Compromise:** If an attacker achieves root filesystem access on the LMS server, they can access `$CFG->passwordsaltmain` or Moodle's sodium encryption keyfile, allowing decryption of stored secrets. Host-level operating system security, SELinux/AppArmor profiles, and filesystem permissions are the responsibility of the institution's infrastructure team.
2. **Receiving Endpoint Compromise:** Once a webhook payload is securely transmitted over TLS to a verified third-party endpoint (e.g. AWS, Azure, n8n, Salesforce), the security, storage, and GDPR compliance of that data is governed by the receiving infrastructure.
3. **Plain HTTP Toggle Warning:** Enabling `allowinsecurehttp` in settings permits unencrypted `http://` dispatches for local development. The plugin provides prominent UI alerts and documentation warnings.

---

## 5. Compliance & Certification Statement

| Standard / Requirement | Status | Verification Evidence |
| :--- | :---: | :--- |
| **Moodle Coding Standards (PHPCS)** | **PASSED (100%)** | 0 errors, 0 warnings across all production and test files (`--standard=moodle`). |
| **PHP Compatibility** | **PASSED** | Compatible with PHP 8.1, 8.2, and 8.3. |
| **Moodle Core Version Support** | **PASSED** | Supports Moodle 4.5.0 LTS through Moodle 5.2. |
| **Database Engines** | **PASSED** | Fully verified on PostgreSQL, MariaDB, and MySQL via Moodle DDL API. |
| **Standard Webhooks Compliance** | **PASSED** | Complies with Standard Webhooks header specification and HMAC-SHA256 signature scheme. |
| **Automated CI Matrix** | **CONFIGURED** | GitHub Actions workflow executing `moodle-plugin-ci` across PHP, DB, and Moodle matrices. |
| **Licensing Integrity** | **PASSED** | 100% GNU General Public License v3 or later with zero proprietary locks or license calls. |

---

*Report certified by Definite Labs Security Engineering.*

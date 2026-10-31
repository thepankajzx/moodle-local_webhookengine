# Changelog

All notable changes to the `local_webhookengine` plugin will be documented in this file.

## [1.1.0] - 2026-11-01

### Added
- Implemented Mutual TLS (mTLS) client certificate authentication (`classes/http_client.php`) for Zero Trust enterprise and government endpoints.
- Added administrative settings `mtlscertpath`, `mtlskeypath`, and `mtlscapath` in `settings.php` for server-wide X.509 client certificate management outside the web root.
- Added per-hook `usemtls` toggle with pre-flight file existence and readability verification, returning safe diagnostic error codes without crashing background cron workers.
- Implemented OAuth2 Client Credentials flow (`classes/oauth2_client.php`) supporting modern cloud APIs (Azure Entra ID, AWS API Gateway, Okta, custom SaaS).
- Supported token endpoint discovery, client ID & client secret authentication, scope negotiation, and high-performance MUC caching (`oauth2_token_{$hookid}`) with dynamic safety margins.
- Enforced strict SSRF validation on OAuth2 token endpoints and encrypted storage for OAuth2 client secrets via Moodle's core encryption subsystem (`\core\encryption`).
- Database schema migration savepoint `2026110104` adding `usemtls`, `authmode`, `oauth2tokenurl`, `oauth2clientid`, `oauth2clientsecret`, and `oauth2scope` to `{local_webhookengine_hook}`.
- Integrated OAuth2 Bearer token injection and mTLS execution into background delivery pipelines (`classes/delivery_service.php`).
- Extended CLI administration tool (`cli/webhook_ctl.php`) to export and import mTLS and OAuth2 configuration metadata with automatic secret redaction.

## [1.0.3] - 2026-11-01

### Added
- Implemented enterprise dual-channel notification dispatcher (`classes/alert_dispatcher.php`) routing critical system alerts (`circuit_breaker_alert`, `ratelimit_alert`, `health_check_alert`) simultaneously to on-platform Moodle administrators and off-platform external DevOps/SRE email addresses (`alertemail`). Ensures infrastructure on-call teams are alerted instantly during downstream outages without requiring an active Moodle session.
- Added `alertemail` administrative setting in `settings.php` supporting custom operational mailboxes, distribution lists, and automated incident response ingest addresses.
- Added command-line administration tool (`cli/webhook_ctl.php`) for diagnostic fleet health checks (`--health`), webhook listings (`--list`), circuit breaker reset (`--reset-circuit=ID`), and transactional configuration export/import (`--export`, `--import`).
- Exported configurations strictly redact signing secrets (`[REDACTED_CLI_MANAGED_KEY]`) and custom authorization headers, preventing key exfiltration in automated CI/CD and server backups.
- Import validates target URLs against SSRF policies and generates fresh 32-byte cryptographic signing secrets within database transactions.
- Implemented real-time performance telemetry service (`classes/telemetry.php`) computing rolling 24-hour success rates, p95 delivery latency percentiles, queue depth, and circuit breaker health.
- Backed by short-TTL MUC application cache (`local_webhookengine/ratelimit`) to avoid production database query load during high-traffic admin dashboard views.
- Upgraded administrative health panel (`templates/health_panel.mustache`) with rolling 24h success rates, p95 latencies, and circuit breaker trip counters.
- Enriched `manage.php` webhook table with per-hook 24h success rate percentages, p95 latency indicators, and last failure error code details.
- Implemented enterprise Circuit Breaker state machine (`classes/circuit_breaker.php`) with `closed`, `open`, and `half_open` states, protecting Moodle cron runners, cURL sockets, and database connections during downstream endpoint outages.
- Configurable failure trip streak threshold (`circuitbreakertripstreak`, default 10) and cooldown period (`circuitbreakercooldown`, default 300s = 5m).
- Automatic half-open probe testing: upon cooldown expiration, a single probe delivery is dispatched to test remote recovery. Successful response immediately restores `closed` healthy state and resets failure streaks; failed response trips circuit back to `open` with fresh cooldown.
- Ingress short-circuiting in `observer.php`: when a target hook's circuit is open, incoming events are recorded directly into `{local_webhookengine_drop_ledger}` with `reasons::CIRCUIT_BREAKER_OPEN` and skipped, completely avoiding database queue bloat and cron lockups.
- Per-hook concurrency slot throttling (`maxconcurrenthook`, default 5) in `rate_limiter.php`, preventing any single degraded endpoint from monopolizing scheduled task execution threads.
- Administrative manual circuit reset action (`reset_circuit`) and visual status badges (`Closed`, `Open`, `Half-open`) in `manage.php`.
- Registered `circuit_breaker_alert` message provider alerting administrators when a circuit trips.
- Database schema migration savepoint `2026110103` adding `circuitstate` and `circuitcooldownuntil` to `{local_webhookengine_hook}`.
- Implemented dual-secret rotation with zero-downtime grace period (`rotationgraceperiod`, default 24h). During active grace period, webhooks are signed with both current and previous secrets via Standard Webhooks multi-signature specification (`webhook-signature: v1,sig1 v1,sig2`), allowing receiving servers (n8n, Zapier, custom endpoints) to verify payloads with either secret without downtime.
- Space-delimited multi-signature verification support in `signer::verify()` adhering strictly to Standard Webhooks specification.
- Database migration savepoint `2026110102` adding `prevsecret` (nullable text) and `secretrotatedat` (nullable int) to `{local_webhookengine_hook}`.
- Implemented high-throughput aggregated drop ledger `{local_webhookengine_drop_ledger}` with atomic 8-slot sharding to eliminate silent event drops with zero lock contention.
- Introduced centralized reason registry (`\local_webhookengine\reasons`) classifying drops (`rate_limited`, `queue_overload`, `hook_paused`, `killswitch_enabled`, `hook_backlog_exceeded`, `hook_disabled_or_missing`, `observer_error`).
- Upgraded event map to v2 (`map_v2`) tracking paused hook status, enabling precise drop recording when events fire during planned webhook downtime.
- Added noisy-neighbor backlog guard (`rate_limiter::is_hook_backlog_exceeded`) to protect shared queue bandwidth from downstream outage storming.
- Added automated orphaned delivery sweeper in `task\check_health` to rescue stuck overdue deliveries without relying on manual intervention.
- Exposed live drop counters in the administration health panel and per-hook status badges in `manage.php`.
- Registered `ratelimit_alert` message provider notifying site administrators when an endpoint reaches rate limit thresholds.
- Introduced dedicated internal network and host allowlist setting (`allowedinternalhosts`) supporting CIDR subnets, exact IPs, and wildcard domain patterns (`*.internal`).
- Dedicated destination domain allowlist (`alloweddestinationdomains`) supporting exact domains and wildcard patterns (`*.partner.org`) to restrict webhook egress to authorized external servers.
- Secure, non-cacheable one-time secret presentation page (`secret_reveal.php`) with HTTP `Cache-Control: no-store` headers and single-use cache token destruction, preventing signing secret leakage in browser history, proxy caches, or database session logs.
- Write-only custom header masking in configuration forms (`Header-Name: (configured)`), preventing plaintext exfiltration of API keys and bearer tokens through HTML markup or browser inspections.
- Explicit `clearheaders` option allowing administrators to securely purge stored credentials.
- Cryptographic payload `bodyhash` (SHA-256) column added to `{local_webhookengine_delivery}` via database migration (`db/upgrade.php` savepoint `2026110101`). Computed at dispatch time, ensuring non-repudiation audit trails even after payload contents are purged under GDPR data retention policies.
- Granular before/after configuration audit diffs in `\local_webhookengine\event\hook_updated` event records, logging all modified non-sensitive fields (`name`, `url`, `events`, `status`, `filters`, `payloadopts`, `timeout`, `maxattempts`) for SIEM and security audit tracking. Secrets and authorization headers remain strictly excluded.
- Added `hook_repository::get_hooks()` centralized repository helper with automatic deletion filtering.
- Comprehensive PHPUnit test coverage (`tests/url_validator_test.php`, `tests/access_control_test.php`, `tests/template_fail_closed_test.php`, `tests/audit_lifecycle_test.php`, `tests/drop_ledger_test.php`, `tests/hook_repository_test.php`, `tests/delivery_replay_test.php`) and automated multi-environment GitHub Actions CI matrix (`.github/workflows/ci.yml`).

### Fixed
- Standardized outbound HTTP `User-Agent` header (`EventWebhooksEngine/$plugin->release`) dynamically sourced from `version.php` via `http_client::get_user_agent()`, eliminating legacy hardcoded `WebhookEngine/1.0.0` version discrepancy across test pings and delivery dispatches.
- Established strict lifecycle status constants in `classes/hook_repository.php` (`STATUS_ACTIVE`, `STATUS_PAUSED`, `STATUS_DELETED`) and `classes/delivery_service.php` (`STATUS_PENDING`, `STATUS_RETRYING`, `STATUS_SUCCESS`, `STATUS_FAILED`), eliminating magic string dependencies.
- Truth in documentation: Removed unverified "Enterprise Certified" claims from `documentation.html` and unreleased version references, aligning `README.md` and user guides to declare tested compatibility for Moodle 4.5 LTS through 5.2.
- Softened and clarified non-blocking architecture documentation: accurately documented that event observation performs two local database queue insertions without synchronous HTTP network dispatch, leaving all outbound delivery to background adhoc cron workers.
- Upgraded `SECURITY.md` with comprehensive institutional security policy including supported versions table, 48-hour response SLA, 7-day critical patch commitment, and ethical researcher Safe Harbor provisions.
- Upgraded release build automation (`build_zips.php`) to dynamically derive release archive filenames from `version.php`.
- Replaced destructive hard-deletion of webhooks with institutional soft-deletion (`status = 'deleted'`). Deleting a webhook now retains historical delivery records and audit trails until standard log retention expiration.
- Enforced strict template fail-closed guarantees: if a custom JSON template cannot be parsed or renders invalid JSON at runtime, delivery execution immediately fails (`template_invalid`) with zero network dispatch. Completely eliminated the silent fail-open fallback to default full envelopes, preventing accidental exfiltration of sensitive student and course data.
- Hardened access control defaults in `db/access.php`: removed default capability grants (`manager => CAP_ALLOW`) across `manage`, `viewlogs`, `replay`, `exportlogs`, and `exportpersonaldata`, restricting webhook configuration, credential handling, and audit export strictly to Site Administrators by default.
- Eliminated signing secret leakage via `\core\notification` session queues during webhook creation and rotation.
- Decoupled plain HTTP development toggle (`allowinsecurehttp`) from SSRF network defense. Enabling plain HTTP no longer disables private IP and loopback blocks or passes blanket `ignoresecurity=true` to Moodle cURL.
- Defeated DNS rebinding TOCTOU attacks via pre-flight DNS resolution, anti-private-range verification across all resolved addresses, and connection pinning using `CURLOPT_RESOLVE`.
- Fixed critical header-wipe regression where pausing or resuming a webhook deleted stored encrypted custom authorization headers. Introduced surgical `hook_repository::set_hook_status()` method to guarantee credential isolation during lifecycle state transitions.
- Hardened webhook replay safety: strictly reject replaying deliveries whose payload has been purged under GDPR data retention policies (`payload_purged_cannot_replay`), preventing dispatch of synthetic `{}` empty bodies over the network.
- Enhanced replay idempotency: manual replays reset attempt counters to 0 while preserving RFC 4122 delivery UUIDs for downstream deduplication.
- Hardened rate limiter and observer fail-open error isolation: cache backend degradation or observer exceptions never abort student activity.

## [1.0.2] - 2026-10-27

### Fixed
- Added missing `status_queued` language string in `lang/en/local_webhookengine.php` for pending deliveries.
- Normalized event names with leading backslashes (`'\\' . ltrim($name, '\\')`) across observer and repository to ensure custom event class subscriptions match correctly.
- Replaced dead support link in `manage.php` footer with active public repository link.
- Aligned documentation files (`documentation.html` and `README.md`) to explicitly declare Moodle 4.5 to 5.2 certified support.

## [1.0.1] - 2026-10-13

### Changed
- Removed all licensing restrictions and tier gates to provide full, unrestricted functionality under GNU GPL v3.
- Migrated HTTP client to Moodle core `\curl` helper with strict SSRF protection.
- Enforced unconditional localhost and private IP validation in URL validator.
- Updated database message provider defaults to use integer bitmasks.
- Switched secret encryption exclusively to Moodle core encryption subsystem.

### Added
- One-time dismissible alert for newly generated webhook signing secrets with copy-to-clipboard action.
- Missing language strings for capabilities and message providers.
- Moodle GPL license boilerplate docblocks in all Mustache templates.

## [1.0.0] - 2026-09-20

### Added
- Generic Moodle event capture engine for any core or third-party event.
- Standard Webhooks v1 signature specification compliance (`webhook-id`, `webhook-timestamp`, `webhook-signature`).
- High-efficiency observer with zero database queries for non-subscribed events.
- Resilient asynchronous background delivery via Moodle ad-hoc tasks.
- Advanced retry backoff schedule (1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h) with jitter.
- Health monitor with automatic pausing for persistently failing endpoints.
- Event filtering by Course ID and Category hierarchy (with recursive subcategories).
- Opt-in payload enrichments for user data, email, course information, and raw event data.
- Custom JSON payload templating engine with strict placeholder validation.
- End-to-end secret and custom header encryption using `\core\encryption`.
- Complete Privacy API implementation with full export, deletion, and external data mapping.
- Full administration interface: Manage Webhooks, Edit/Create form, Deliveries log, and Replay capabilities.

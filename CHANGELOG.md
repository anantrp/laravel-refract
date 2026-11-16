# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

0.2 splits sending into collecting and draining, and cuts the configuration to ten environment variables.

### Added

- `REFRACT_DRAIN=queue`: finished run trees are stored in a collector and exported from your queue workers by a unique `DrainTraces` job on the `refract` queue. It retries network errors, 408, 429 and 5xx with a 10, 60, 300 s backoff, honouring `Retry-After` up to 300 s, and never reaches `failed_jobs`, even when its worker dies or kills it at its timeout. It ends within 45 s (job `timeout` 60 s, lease 120 s), leaving what it could not send in time to the next drain. Delivery is at least once.
- Collectors, chosen with `REFRACT_COLLECTOR`: `redis` (a Redis 6.2+ stream) and `database` (a `refract_traces` table, published with `--tag=refract-migrations`, claimed with `SKIP LOCKED` on MySQL 8+, MariaDB 10.6+ and PostgreSQL). Each holds at most 64 MB, and drops new trees with one warning at the cap.
- A `Context` key mapped to `user.id` or `session.id` sets it on a run that is not saved. A saved conversation's session id and, on Langfuse, a user participant win over the mapped values.
- One run records at most 1,000 spans. Past that, its new spans are dropped and the run is marked `laravel.ai.truncated=true`.
- Tool approval events are recorded on the run they belong to.

### Changed

- `REFRACT_DRAIN=sync`, the default, exports once in the process, after the response: best effort, a tree that cannot be exported is dropped with one warning. The HTTP timeout is 5 s (15 s from the queue drain).
- A destination that cannot send (no endpoint, a missing Langfuse key, an invalid URL) logs one warning at boot, and Refract registers nothing, as if disabled.
- Exports are always gzipped.
- Captured values are always cut at 128 KB.
- `service.name` is `APP_NAME` (`Laravel` when empty) and `deployment.environment.name` is `APP_ENV`.
- Requires `laravel/ai` 1.4 or higher. A tool that throws is a failed `execute_tool` span; since 1.4 the SDK hands the failure back to the model, so the run goes on and ends ok.

### Removed

These environment variables are no longer read:

- `REFRACT_TRANSPORT`: use `REFRACT_DRAIN` (`sync` or `queue`). `null` is `REFRACT_ENABLED=false`.
- `OTEL_SERVICE_NAME` and `REFRACT_ENVIRONMENT`: set `APP_NAME` and `APP_ENV`.
- `OTEL_EXPORTER_OTLP_ENDPOINT` and `OTEL_EXPORTER_OTLP_HEADERS`: set `REFRACT_OTLP_ENDPOINT` (the full traces URL) and `REFRACT_OTLP_HEADERS`.
- `OTEL_EXPORTER_OTLP_COMPRESSION`: exports are always gzipped.
- `REFRACT_CAPTURE_MAX_BYTES`: the cap is 128 KB.
- `REFRACT_CONTEXT_PARTICIPANT_TYPE` and `REFRACT_CONTEXT_PARTICIPANT_ID`: a saved conversation still gives the participant; otherwise map `Context` keys to `user.id` and `session.id` in `context.attributes`.

Also removed: the `queue` and `null` transports and their `ExportSpans` job; the `platform` key of each destination in the published config (now ignored); the partial sends of runs still open in a console process; and retrying a failed export in the process.

### Fixed

- The run that titles a new conversation nests under the run that started it, in its trace, in place of a trace of its own.

## [0.1.0] - 2026-10-09

First release.

### Added

- Records every Laravel AI SDK agent run as OpenTelemetry spans: `invoke_agent`, `chat` and `execute_tool`. Sub-agents nest under their tool. Failover and tool approvals are events. Streams are traced like `prompt()`.
- OTel GenAI attributes plus `laravel.ai.*`, `session.id`, the run's participant, `service.name` and `deployment.environment.name`. `error.type` on failed spans. Joins an active OpenTelemetry trace.
- Destinations: `otlp` (any OTLP/HTTP JSON backend) and `langfuse`. Redirects are never followed.
- Transports: `sync`, `queue` (async drivers only) and `null`. No network call before the response.
- Opt-in content capture with a byte cap per value and an optional `mask` class. Files and media are never recorded.
- Laravel `Context` keys mapped to span attributes through the config.
- Never breaks the app: guarded listeners, one warning per kind, a 1,000-span buffer cap.
- In a command, queue worker or tinker, finished runs are sent while the process keeps going. Open runs stay whole and keep their state. Web requests send once, after the response.
- One warning when a destination accepts a batch (2xx) but its OTLP `partialSuccess` refuses spans, with the count and the message. Credentials in the message are masked. The refused spans are not retried.
- A batch over 4 MB of OTLP JSON (before gzip) is sent in parts of 4 MB or less, cut between run trees. A span over 4 MB is sent alone with one warning.
- The HTTP timeout is 15 s, so a 4 MB part has time to reach the destination.
- Exports are gzipped with `Content-Encoding: gzip` by default, for both destinations. `OTEL_EXPORTER_OTLP_COMPRESSION=none` turns it off. If gzip fails, the export is sent plain.
- The `queue` job tries 4 times, 10 s, 60 s, then 300 s apart. A `Retry-After` on a 429 or 503 can make it wait longer, at most 300 s.
- Refract is never tied to the app's database transactions. An app rollback does not remove spans.
- A send in the process while a console process runs keeps its spans when the destination fails, and tries them again later. Once a part cannot connect, the later parts of that send wait for the next try.

[Unreleased]: https://github.com/anantrp/laravel-refract/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/anantrp/laravel-refract/releases/tag/v0.1.0

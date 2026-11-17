# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Every dropped run tree is counted by reason in the default cache store and fires an `Anantrp\Refract\Events\TracesDropped` event (`count`, `reason`). The reasons are `paused`, `unavailable`, `rejected`, `unfinished`, `error`, `full`, `transaction`, `encode`, `decode` and `run_cap` (whose count is spans), and `poison`.

- With `REFRACT_DRAIN=queue`, a push warns when the oldest due tree in the store has waited over 10 minutes: no worker reads the `refract` queue. Both collectors implement a new `Anantrp\Refract\Contracts\ReportsStats` contract (`stats()`: trees, bytes, cap, oldest due age, drops at the cap, most failed attempts).

- `php artisan refract:status` (`--json`) shows the destination, drain, store, drops by reason, pause or backoff, and the last export success and failure. It exits 1 when the store cannot be read or has a tree waiting over 10 minutes, traces were dropped since its last run, or exports have failed for 10 minutes with no success.

- `php artisan refract:test` sends one test trace straight to the destination, past the queue drain and any pause, and says whether it arrived, was rejected, or could not be sent.

### Changed

- A warning is logged at most once per kind every 10 minutes, shared through the default cache store, and the next one says how many were held back (`(42 more since the last warning)`). Before, each process logged a kind once, ever, and stopped after 10 kinds: a long-lived queue worker went quiet during a long outage.

- With a content mask, each distinct value is masked once per request or job, not once per step it appears in: a 10-step run no longer masks its history 10 times. The mask should give the same output for the same input.

- With content capture on, a run records at most about 2 MB of step input in full. Past that, each step records only its new messages, after one `[N earlier messages omitted, see step 1]` message, and the run span gets `laravel.ai.content_truncated=true`. Step 1, the run's prompt and output, step outputs and tool content are never cut this way. A run under the budget records exactly what it did before.

- The queue drain's collectors store trees gzipped (`gz1:` and base64, so still plain text in the database), so the 64 MB cap holds several times more traces. Trees stored by 0.3 still read. A drain claims 1 MB of stored trees at a time, about one 4 MB request of JSON. Requires `ext-zlib`. **Upgrading:** a tree stored by 0.4 cannot be read by 0.3, so rolling back with trees still stored drops them, each with a `decode` warning.

### Fixed

- With `REFRACT_DRAIN=queue`, one tree that a destination keeps failing on (a 5xx for that payload) no longer holds back the trees claimed with it. Each tree counts its failed exports: after 3 it is sent apart from the others, and it is dropped as `poison` at 10, counting past 3 only failures while the destination took other trees. A long outage, or a destination answering `429`, drops nothing. `refract:status` shows the most failed attempts of a stored tree. **Upgrading:** the database collector needs a new migration: run `php artisan vendor:publish --tag=refract-migrations` and `php artisan migrate`. `Collector::release()` takes a third `$failed` argument and the contract adds `attempts()`: a collector of your own must add both.

- The queue drain no longer sends trees again that an earlier request of the same drain delivered, when a later request fails with a network error, 408, 429 or 5xx.

- The database collector keeps a running total of its stored bytes in the default cache, so a push no longer sums the table each time: it sums at most once a minute, and every claim and ack sets the total right.
- The Redis collector takes over expired leases 100 at a time instead of one by one, and hands back at once the ones a full claim did not take.

### Removed

- `DatabaseCollector::dropped()`. The drop count is now kept for every process, in the default cache store.

## [0.3.0] - 2026-11-17

0.3 makes the sync drain fail fast: a destination that is down costs a request or a queue job at most one bounded export, then nothing until it comes back.

### Changed

- With `REFRACT_DRAIN=sync`, an export takes at most 5 s in all, however many requests it makes; trees it could not send in time are dropped with one warning. Before, each request had its own 5 s.
- Connecting to the destination times out after 1 s with `sync` (5 s from the queue drain), so a destination that never answers a connection costs a run 1 s, not 5 s.
- With `REFRACT_DRAIN=sync`, a failed export (network error, timeout, 408, 429 or 5xx) pauses exports for 30 s, doubling on each failure in a row up to 5 minutes. While paused, trees are dropped without a request, with one warning; then one export, in one process, tries again, and an answer ends the pause everywhere. The pause is shared through the default cache store, so PHP-FPM requests and queue workers share it too.
- Exports go through an HTTP client of Refract's own (`refract.http` in the container), not the `Http` facade: the app's global HTTP middleware and options, `Http::fake()` and request events (Telescope, Pulse, Nightwatch) no longer see them. **Upgrading:** a test that faked exports with `Http::fake()` should fake `app('refract.http')` instead, and headers or proxy options added through global HTTP middleware no longer reach the destination.

## [0.2.0] - 2026-11-16

0.2 splits sending into collecting and draining, and cuts the configuration to ten environment variables.

### Added

- `REFRACT_DRAIN=queue`: finished run trees are stored in a collector and exported from your queue workers by a unique `DrainTraces` job on the `refract` queue. It retries network errors, 408, 429 and 5xx with a 10, 60, 300 s backoff, honouring `Retry-After` up to 300 s, and never reaches `failed_jobs`, even when its worker dies or kills it at its timeout. It ends within 45 s (job `timeout` 60 s, lease 120 s), leaving what it could not send in time to the next drain; a big tree cut part way keeps its progress, and only its unsent spans go back. Delivery is at least once.
- Collectors, chosen with `REFRACT_COLLECTOR`: `redis` (a Redis 6.2+ stream) and `database` (a `refract_traces` table, published with `--tag=refract-migrations`, claimed with `SKIP LOCKED` on MySQL 8+, MariaDB 10.6+ and PostgreSQL). Each holds at most 64 MB, and drops new trees with one warning at the cap. The database collector never writes inside a transaction open on its connection.
- A `Context` key mapped to `user.id` or `session.id` sets it on a run that is not saved. A saved conversation's session id and, on Langfuse, a user participant win over the mapped values.
- One run records at most 1,000 spans. Past that, its new spans are dropped and the run is marked `laravel.ai.truncated=true`.
- Tool approval events are recorded on the run they belong to.

### Changed

- In a console process, a run tree that ends inside a database transaction is handed on after the transaction ends, so an export never holds it open and a stored tree or drain job is never rolled back with it.
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

[Unreleased]: https://github.com/anantrp/laravel-refract/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/anantrp/laravel-refract/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/anantrp/laravel-refract/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/anantrp/laravel-refract/releases/tag/v0.1.0

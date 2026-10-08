# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - unreleased

First release.

### Added

- Records every Laravel AI SDK agent run as OpenTelemetry spans: `invoke_agent`, `chat` and `execute_tool` spans, sub-agents nested under their tool, failover and tool approvals as events, streams traced like `prompt()`.
- OTel GenAI attributes plus `laravel.ai.*`, `session.id`, the run's participant, `service.name` and `deployment.environment.name`. `error.type` on failed spans. Joins an active OpenTelemetry trace.
- Destinations: `otlp` (any OTLP/HTTP JSON backend) and `langfuse`. Redirects are never followed.
- Transports: `sync`, `queue` (async drivers only) and `null`. No network call before the response.
- Opt-in content capture with a byte cap per value and an optional `mask` class. Files and media are never recorded.
- Laravel `Context` keys mapped to span attributes through the config.
- Never breaks the app: guarded listeners, one warning per kind, a 1,000-span buffer cap.
- In a command, queue worker or tinker, finished runs are sent while the process keeps going: when they hold 500 spans or 5 s after the last send (checked when a top-level run ends), and when the buffer is full. Open runs stay whole and keep their state. Web requests still send once, after the response.
- One warning when a destination accepts a batch (2xx) but its OTLP `partialSuccess` refuses spans, with the count and the message. Credentials in the message are masked. The refused spans are not retried.
- A batch over 4 MB of OTLP JSON (before gzip) is sent in parts of 4 MB or less, cut between run trees. A run tree is cut only when it alone is too big. A span over 4 MB is sent alone with one warning. `sync` sends the parts one after another and a failed part does not stop the next ones (failures warn once per kind); `queue` pushes one job per part.
- The HTTP timeout is 15 s, so a 4 MB part has time to reach the destination.
- Exports are gzipped with `Content-Encoding: gzip` by default, for both destinations. `OTEL_EXPORTER_OTLP_COMPRESSION=none` turns it off. If gzip fails, the export is sent plain.
- The `queue` job tries 4 times, 10 s, 60 s, then 300 s apart, so a batch survives an outage of about 6 minutes. A `Retry-After` in whole seconds on a 429 or 503 makes it wait longer, at most 300 s, never shorter. An HTTP date, a negative number or text is ignored.
- Refract is never tied to the app's database transactions: the export job ignores `after_commit`, and sends while a console process runs never write to a `database` queue (they export in the process). An app rollback does not remove spans.
- Any send exported in the process while a console process runs (`sync`, or `queue` on `database`, on a driver that exports with `sync`, for a part too big for the queue, or after a failed push) that cannot reach the destination or gets 408, 429 or 5xx keeps its spans. It tries again at a later send, at least 5 s later, or after `Retry-After` (at most 300 s). Once a part cannot reach the destination, the later parts of that send are kept without a try. A full buffer tries at once, without that wait; after a failed try it tries again at the first new span after an open top-level run ends or after the wait is over, so a destination that comes back gets new spans again. On `redis`, `sqs` and `beanstalkd` the send is a queue job with its own retries.

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
- One warning when a destination accepts a batch (2xx) but its OTLP `partialSuccess` refuses spans, with the count and the message. Credentials in the message are masked. The refused spans are not retried.

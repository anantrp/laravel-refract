# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### 0.1.0

First release.

- Records every Laravel AI SDK agent run as an OpenTelemetry trace: `invoke_agent`, `chat` and `execute_tool` spans, sub-agents nested under their tool, failover and tool approvals as events, streams traced like `prompt()`.
- OTel GenAI attributes plus `laravel.ai.*`, `session.id`, the run's participant, `service.name` and `deployment.environment.name`. Joins an active OpenTelemetry trace.
- Destinations: `otlp` (any OTLP/HTTP JSON backend) and `langfuse`.
- Transports: `sync`, `queue` (async drivers only) and `null`. No network call before the response.
- Opt-in content capture with a byte cap per value and an optional `mask` class. Files and media are never recorded.
- Laravel `Context` keys mapped to span attributes through the config.
- Never breaks the app: guarded listeners, one warning per kind, a 1,000-span buffer cap.

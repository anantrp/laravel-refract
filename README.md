# Laravel Refract

OpenTelemetry exports for the Laravel AI SDK with pluggable observability adapters.

Refract records every [Laravel AI SDK](https://github.com/laravel/ai) agent run as OpenTelemetry spans. It sends them as OTLP JSON over HTTP to [Langfuse](https://langfuse.com) or to any OTLP backend.

Install it, set a few environment variables, and you are done. There is no API to learn and no code to change.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- `laravel/ai` 1.4 or higher

Refract uses `open-telemetry/api` only. It does not need the OpenTelemetry SDK.

Laravel Octane is not supported in 0.1.

## Installation

```bash
composer require anantrp/laravel-refract
```

The service provider is discovered automatically. Refract does nothing useful until you tell it where to send traces. Until then it logs one warning and exports nothing.

## Quick Start

### Langfuse

```ini
REFRACT_DESTINATION=langfuse
LANGFUSE_PUBLIC_KEY=pk-lf-...
LANGFUSE_SECRET_KEY=sk-lf-...
```

Traces go to Langfuse Cloud (`https://cloud.langfuse.com`). Set `LANGFUSE_BASE_URL` for another region or your own Langfuse host.

### Any OTLP Backend

```ini
REFRACT_DESTINATION=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example.com
OTEL_EXPORTER_OTLP_HEADERS="x-api-key=your-key"
```

`otlp` is the default destination, so the first line is optional.

Run any agent. Each run is recorded as a tree of spans. A run starts a new trace, or joins your app's active OpenTelemetry trace when there is one. A sub-agent run nests inside the run that called it.

## Configuration

All settings come from environment variables.

| Variable | Default | What it does |
| --- | --- | --- |
| `REFRACT_ENABLED` | `true` | When `false`, Refract registers no listener at all. |
| `REFRACT_DESTINATION` | `otlp` | `otlp` or `langfuse`. See [Destinations](#destinations). |
| `REFRACT_ENVIRONMENT` | `APP_ENV` | Sent as `deployment.environment.name`. |
| `OTEL_SERVICE_NAME` | `APP_NAME` | Sent as `service.name`. |
| `REFRACT_OTLP_ENDPOINT` | none | `otlp`: the full URL traces are posted to. |
| `REFRACT_OTLP_HEADERS` | none | `otlp`: headers for any OTLP endpoint. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | none | `otlp`: a base URL. `/v1/traces` is appended. Used only when `REFRACT_OTLP_ENDPOINT` is not set. |
| `OTEL_EXPORTER_OTLP_HEADERS` | none | `otlp`: headers sent only to `OTEL_EXPORTER_OTLP_ENDPOINT`. |
| `OTEL_EXPORTER_OTLP_COMPRESSION` | `gzip` | `gzip` or `none`. Both destinations: the only `OTEL_EXPORTER_OTLP_*` variable that also applies to `langfuse`. See [Compression](#compression). |
| `LANGFUSE_BASE_URL` | Langfuse Cloud | `langfuse`: your Langfuse host. |
| `LANGFUSE_PUBLIC_KEY` | none | `langfuse`: required. |
| `LANGFUSE_SECRET_KEY` | none | `langfuse`: required. |
| `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` | `false` | Record prompts, outputs and tool data. See [Content Capture](#content-capture). |
| `REFRACT_CAPTURE_MAX_BYTES` | `131072` (128 KB) | The cap per captured value. At least `64`. |
| `REFRACT_CONTEXT_PARTICIPANT_TYPE` | `refract.participant_type` | The `Context` key that holds the participant type. |
| `REFRACT_CONTEXT_PARTICIPANT_ID` | `refract.participant_id` | The `Context` key that holds the participant id. |

Rules for every variable:

- Missing or empty (`FOO=`) means the default, with no warning.
- Invalid means the default plus one warning. For example: text where a number goes, `0` or a negative number, or a `REFRACT_CAPTURE_MAX_BYTES` under 64. An unknown destination, or a boolean where a name goes, is also invalid.
- Invalid headers mean no headers from that variable, plus one warning.
- An invalid `REFRACT_OTLP_ENDPOINT` or `LANGFUSE_BASE_URL` exports nothing, plus one warning. Refract never falls back to another host, so your keys and headers never go to a host you did not name.
- An invalid `OTEL_EXPORTER_OTLP_ENDPOINT` counts as not set, plus one warning.

Everything works with `php artisan config:cache`.

### Publishing the Config

```bash
php artisan vendor:publish --tag=refract-config
```

This writes `config/refract.php`. Two settings live only in this file: the `Context` attribute map (`context.attributes`) and the content `mask` (`capture.mask`).

If you edit `destinations` in a published config, keep each destination's `'platform'` line. A destination without a valid `'platform'` class exports nothing and logs one warning.

```php
'langfuse' => [
    'platform' => LangfusePlatform::class, // keep this line
    'url' => env('LANGFUSE_BASE_URL'),
    // ...
],
```

## What a Trace Looks Like

Each agent run is one `invoke_agent` span, named after the agent's class. Each model call in the run is a `chat` span under it. Each tool call is an `execute_tool` span under it. Children are in start order.

```text
invoke_agent SupportAgent
  chat gpt-5
  execute_tool LookupOrder
  chat gpt-5
```

- **Sub-agents.** An agent called from a tool nests under that tool's span, in the same trace. Its run is named after that tool. It uses the session and participant of its parent run.
- **Failover.** When a provider fails and the next one answers, there is still one run span. It shows the provider and model that answered. The failed attempt is a `laravel.ai.failover` event with its provider, model and exception class.
- **Tool approvals.** A run that pauses for approval has a `laravel.ai.tool_approval.requested` event per tool call, with the tool name and call id. A run resumed from approvals has a `laravel.ai.tool_approval.resolved` event per decision, with the call id, `laravel.ai.tool_approval.action` (`approve`, `reject` or `edit`) and `laravel.ai.tool_approval.approved`. The tool name is there when the call is in the messages the resumed prompt was given; a conversation the SDK loads from its store is not read. A decision for every other call has the call id `*`. A run resumed from approvals records no prompt, since none was sent.
- **Streams.** A stream read to the end gives the same tree as `prompt()`. A stream read again after it stopped is a separate run.
- **Abandoned spans.** A span still open when Refract exports (for example, a stream the app stopped reading) is closed as abandoned. It has no OTel status and the attribute `laravel.ai.abandoned=true`. Langfuse shows it as a warning with the message `abandoned`.
- **Truncated runs.** One run records at most 1,000 spans, sub-agent runs included. Past that, its new spans are dropped with one warning, and the top-level run span gets `laravel.ai.truncated=true`. Other runs are not affected.
- **Errors.** A run, model call or tool that throws gets status `error` and the attribute `error.type`, both the exception class. Your app gets the same exception. The exception message is never recorded.

### Attributes

| Attribute | On |
| --- | --- |
| `gen_ai.operation.name` | all spans |
| `gen_ai.provider.name`, `gen_ai.request.model` | `invoke_agent`, `chat` |
| `gen_ai.agent.name` | `invoke_agent` |
| `gen_ai.response.model`, `gen_ai.response.finish_reasons`, `gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens` | `chat` |
| `gen_ai.tool.name`, `gen_ai.tool.type` | `execute_tool` |
| `laravel.ai.invocation_id`, `laravel.ai.agent.class` | `invoke_agent` |
| `laravel.ai.step`, `laravel.ai.final_step` | `chat` |
| `laravel.ai.tool_invocation_id` | `execute_tool` |
| `session.id`, `gen_ai.conversation.id` | `invoke_agent`, from the SDK conversation id |
| `laravel.ai.participant.type`, `laravel.ai.participant.id` | `invoke_agent`. See [Participant](#participant) |
| `error.type` | any span that failed: the exception class |
| `laravel.ai.abandoned` | any span closed as abandoned |
| `laravel.ai.truncated` | a top-level span whose run lost spans to the per-run cap |
| your mapped `Context` keys | `invoke_agent`. See [Context Attributes](#context-attributes) |

Every trace also carries the resource attributes `service.name` and `deployment.environment.name`.

Content attributes (`gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.tool.call.arguments`, `gen_ai.tool.call.result`) are added only with content capture on.

### Joining an Active Trace

If your app already has an active OpenTelemetry span (through `open-telemetry/api`), the run joins that trace as a child of that span. Otherwise each top-level run starts a new trace; a sub-agent run stays in the trace of the run that called it.

## Destinations

### `otlp`

Any backend that accepts OTLP over HTTP with JSON.

There are two ways to set the endpoint:

- `REFRACT_OTLP_ENDPOINT` is the full traces URL, used as is. It gets only `REFRACT_OTLP_HEADERS`. Your `OTEL_EXPORTER_OTLP_HEADERS` are never sent to it, so another exporter's credentials stay with that exporter.
- `OTEL_EXPORTER_OTLP_ENDPOINT` is a base URL. Refract appends `/v1/traces`. It gets `OTEL_EXPORTER_OTLP_HEADERS` plus `REFRACT_OTLP_HEADERS`. On the same header name, the `REFRACT_` value wins.

`REFRACT_OTLP_ENDPOINT` wins when both are set. The `_TRACES_` variants of the OTel variables are not read.

Headers use the OpenTelemetry format, with URL-encoded values:

```ini
REFRACT_OTLP_HEADERS="x-api-key=abc123,x-team=my%20team"
```

### `langfuse`

```ini
REFRACT_DESTINATION=langfuse
LANGFUSE_BASE_URL=https://us.cloud.langfuse.com
LANGFUSE_PUBLIC_KEY=pk-lf-...
LANGFUSE_SECRET_KEY=sk-lf-...
```

- An empty `LANGFUSE_BASE_URL` means Langfuse Cloud.
- A missing key exports nothing and logs one warning.
- Refract posts to `/api/public/otel/v1/traces` with Basic auth from your keys.
- The environment name is changed to fit Langfuse: lowercase, anything but letters, digits, `-` and `_` becomes `-`, at most 40 characters. `Staging EU 1` becomes `staging-eu-1`. An empty result becomes `default`.
- Langfuse names tool observations by the tool name, so `execute_tool LookupOrder` shows as `LookupOrder`.
- Langfuse stores times in milliseconds. Spans that start in the same millisecond are moved to distinct milliseconds, so they keep their order. Parents still cover their children and events stay inside their span.
- `user.id` is set only from a user participant: the participant type must implement `Illuminate\Contracts\Auth\Authenticatable`. A `Team` participant gets no `user.id`. A `Context` mapping never sets `user.id`.
- A `Context` mapping never sets a `langfuse.*` attribute. Refract sets those itself, for example on abandoned spans.

### Compression

Every export is gzipped and sent with `Content-Encoding: gzip`, to `otlp` and to `langfuse`. Set `OTEL_EXPORTER_OTLP_COMPRESSION=none` to send it plain, for example through a proxy that does not accept gzip. It is the only `OTEL_EXPORTER_OTLP_*` variable that also applies to `langfuse`. If gzip fails, the export is sent plain.

### Request Size

Refract packs whole run trees (a run and every span under it, sub-agent runs included) into requests of at most 4 MB of OTLP JSON, counted before gzip. So a destination with a body size limit does not refuse them. A big tree usually comes from content capture. A run tree over 4 MB alone is the exception: its spans are packed one by one, so the tree is split over several requests, with one warning. A single span over 4 MB is sent alone, with one warning: the destination may refuse it.

On Langfuse, spans that start in the same millisecond are moved apart within each request. Siblings of a run tree that was split over two requests can still share a millisecond.

## Sending

Refract hands a run tree on once its top-level run ends. Nothing touches the network before a web response is sent.

- **Web request:** every finished run tree is sent once, after the response is sent. Runs that are still open then are closed as abandoned and sent with them.
- **Console process** (command, queue worker, tinker): each run tree is sent when its top-level run ends, in a request of its own. The end of an async queued job and the end of the outermost command close the runs still open as abandoned, and send them.

A sync-driver job or an `Artisan::call()` inside a web request does not send on its own. Its spans leave with the request.

Refract exports in the same process, once. It is best effort: the export waits up to 5 seconds for the destination, and a tree that cannot be exported is dropped with one warning. It is never tried again.

### Export Failures

| Result | What happens |
| --- | --- |
| 2xx | Done |
| 2xx whose `partialSuccess` refuses spans or has a message | Done, one warning |
| Network error, 408, 429, 5xx | Dropped, one warning. The later requests of that export are not tried. |
| Any other status (3xx, 400, 401, 403, 404, ...) | Dropped, one warning. The later requests of that export are still sent. |

A rejected batch's warning names the status and the first 200 characters of the response body. Refract masks credentials that the destination echoes in the body as `[removed]`. It masks `Bearer` and `Basic` tokens. It also masks values under key names that contain `key`, `token`, `secret`, `auth`, `password`, `passwd`, `credential` or `cookie`, in JSON, in header lines and in `key=value` pairs. Other text, such as a plain error line, is kept.

A 2xx can carry an OTLP `partialSuccess` (JSON answers only). When it refuses spans or has a message, one warning names the refused count and the message, masked the same way. The answer does not say which spans were refused. They are not retried, as the OTLP spec asks. An empty body, `{}` or a body that is not JSON logs nothing.

Redirects are never followed, so your keys and headers never go to another host. A 3xx is dropped like any other rejected status. The HTTP timeout is 5 seconds.

### No Endpoint

When the destination has no endpoint, or Langfuse has no keys, nothing is exported and one warning is logged. The warning comes on the first export, not at boot.

## Content Capture

Content capture is off by default. With it off, spans hold no prompt, output, tool argument, tool result or error message. Turn it on with:

```ini
OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT=true
```

With capture on, Refract records:

- the run's prompt and final output (`invoke_agent`),
- each model call's input messages and output, including tool calls (`chat`),
- tool arguments and the tool result (`execute_tool`).

The agent's instructions are not recorded. Exception messages are never recorded, even with capture on.

How values are recorded:

- **Byte cap.** Each value is cut at `REFRACT_CAPTURE_MAX_BYTES` (default 128 KB, at least 64), at a character border, and marked with its original size: `…[cut, original size N bytes]`.
- **No files or media.** Attachments and files are never recorded, with capture on or off: no bytes, no name, no URL, no size. A file inside a value, for example a tool that returns an image, becomes `[file]`.
- **Tool results in step history.** In a `chat` span's input messages, a tool result is only a reference (tool name and call id). The result itself is on the `execute_tool` span.
- **No built-in redaction.** Refract does not look for secrets. Use a `mask`.

### Tool Results

| Tool result | Recorded as |
| --- | --- |
| A string | The string |
| An array or Collection | JSON, in full. Refract walks the arrays and Collections inside it. |
| A number or boolean (top level only) | Cast to a string. `false` becomes an empty string. |
| A `Stringable` (top level only) | Its `__toString()`, as the SDK does |
| A backed enum inside an array or Collection | Its value |
| A file, at any level of arrays and Collections | `[file]` |
| Any other object | Its class name. Refract runs none of its methods. |
| A value that cannot be encoded as JSON, for example one with a pure enum | `[not encodable as JSON]`, with one warning |

### Mask

A mask is an invokable class. Refract makes it from the container and gives it every captured string, before the byte cap. Set it in the published config, as a class name, so `config:cache` works:

```php
'capture' => [
    'content' => env('OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT'),
    'max_bytes' => env('REFRACT_CAPTURE_MAX_BYTES'),
    'mask' => App\Support\MaskSecrets::class,
],
```

```php
namespace App\Support;

class MaskSecrets
{
    public function __invoke(string $value): string
    {
        return preg_replace('/sk-[A-Za-z0-9]{20,}/', '[secret]', $value) ?? '';
    }
}
```

The mask fails closed. When it throws or does not return a string, the value becomes `<fully masked due to failed mask function>` and one warning is logged. When the class cannot be made or is not invokable, every value is masked that way. The run always continues.

The mask is not called for the output or tool result of a span dropped at the cap (see [Bounded Memory](#bounded-memory)). Its prompt, input and tool arguments still go through the mask.

## Participant

The participant is who the run is for. Refract records it as `laravel.ai.participant.type` and `laravel.ai.participant.id`. The type is always the full class name, for example `App\Models\User`.

Refract uses one source per run, never both:

1. **Saved run with a participant** (`->forUser($user)`, `->forParticipant($team)`, `continue($id, as: $user)`): the conversation participant. `Context` is ignored, even when it is set.
2. **Any other run** (not saved, or saved with no participant, such as `continue($id)` without `as:`): the two `Context` keys, used only when both are set. If only one is set, no participant is recorded.

```php
use Illuminate\Support\Facades\Context;

Context::add('refract.participant_type', App\Models\User::class);
Context::add('refract.participant_id', $user->id);
```

A morph alias in the type key (for example `user`) is turned into its class. The key names come from `REFRACT_CONTEXT_PARTICIPANT_TYPE` and `REFRACT_CONTEXT_PARTICIPANT_ID`.

`Context` has no effect on a saved run with a participant. For a saved run, use `->forUser()` or `->forParticipant()`.

### Scheduled Agents

A scheduled agent run often concerns a user who did not start it. Set the participant in the job:

```php
// Saved run: the conversation participant is used.
WeeklySummaryAgent::make()->forUser($user)->prompt('Summarize my week.');

// Run not saved: set both Context keys.
Context::add('refract.participant_type', App\Models\User::class);
Context::add('refract.participant_id', $user->id);

WeeklySummaryAgent::make()->prompt('Summarize the week.');
```

Who started the run is a separate fact. Send it through the [Context attribute map](#context-attributes):

```php
// config/refract.php
'attributes' => [
    'trigger' => 'app.trigger',
],
```

```php
Context::add('trigger', 'schedule');
```

## Context Attributes

`context.attributes` in the published config maps Laravel `Context` keys to span attribute names:

```php
'context' => [
    // ...
    'attributes' => [
        'tenant_id' => 'tenant.id',
    ],
],
```

- Only scalar values are copied, and only onto the `invoke_agent` span. Sub-agents use their parent run's values.
- A mapped key never replaces an attribute Refract sets on the run span, even an empty one.
- A mapped key never sets `user.id`, and on Langfuse never sets a `langfuse.*` attribute.
- A numeric attribute name, such as `'123'`, is sent as the string `"123"`.

## Safety

- **Refract never breaks your app.** Every listener and lifecycle hook is guarded. A failure inside Refract logs one warning and the run goes on. Your app's own exceptions pass through unchanged.
- **No extra calls.** Refract never calls agent methods that run your code (`instructions()`, `tools()`) and runs no queries. A run is named without calling the agent's `name()`. To name a tool span, Refract reads the tool name the way the SDK does. This calls `name()` once per tool call, on a tool or on an agent used as a tool. The SDK makes the same call.
- **Warnings.** Each warning is logged at the `warning` level, prefixed `[refract]`, once per kind per process. After 10 different warnings, Refract stays silent.

### Bounded Memory

- One run records at most 1,000 spans, sub-agent runs included. Past that, Refract drops the run's new spans, logs one warning and marks the run `laravel.ai.truncated=true`. Other runs are not affected.
- A run dropped at the cap stays dropped. Its steps, tools and sub-agent runs are dropped too. So they never show up as traces of their own.
- A console process holds only its open runs: each run tree leaves when it ends. A web request holds its finished run trees until the response is sent.
- A stream that the app stopped reading stays open until the end of the process.

## Known Limitations

- Laravel Octane is not supported.
- With a morph map, the participant type is the full class name. So it does not match the `participant_type` column of the `agent_conversations` table, which holds the alias.
- With no `mask` set, a secret inside captured content is sent as is.
- Only agent runs are traced. Embeddings, images, audio and other SDK operations are not.
- The send after the response still runs in the same PHP-FPM worker. A destination that is down keeps that worker busy for up to 5 seconds per export.
- Sends inside a queue job run under that job's `timeout`. A slow or down destination adds up to 5 seconds per run the job makes. Give jobs that run agents room in their `timeout`.
- Spans not yet sent are lost when the worker kills a job at its `timeout`. They are also lost when a command ends by a signal, `exit()` or `dd()`.
- A destination that is down or slow loses the traces sent meanwhile: nothing is tried again.

## Contributing

Run the tests, the code style check and static analysis:

```bash
composer test
vendor/bin/pint --test
composer analyse
```

The workbench runs real scenarios and reads the traces back from Langfuse. Copy `workbench/.env.example` to `workbench/.env`, add the keys of a Langfuse project you use for testing, then:

```bash
composer build
vendor/bin/testbench refract:check R1
```

`refract:check` runs the named scenario (one per file in `workbench/expected`), reads the trace from Langfuse, and compares it to `workbench/expected/{scenario}.txt`. It prints `PASS` or `FAIL`.

## License

Refract is open-sourced software licensed under the [MIT license](LICENSE).

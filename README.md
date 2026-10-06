# Laravel Refract

Refract records every [Laravel AI SDK](https://github.com/laravel/ai) agent run as OpenTelemetry spans. It sends them as OTLP JSON over HTTP to [Langfuse](https://langfuse.com) or to any OTLP backend.

Install it, set a few environment variables, and you are done. There is no API to learn and no code to change.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- `laravel/ai` 1.x

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

To export from a queue worker instead of after the response, add:

```ini
REFRACT_TRANSPORT=queue
```

Run any agent. Each run is recorded as a tree of spans. A run starts a new trace, or joins your app's active OpenTelemetry trace when there is one. A sub-agent run nests inside the run that called it.

## Configuration

All settings come from environment variables.

| Variable | Default | What it does |
| --- | --- | --- |
| `REFRACT_ENABLED` | `true` | When `false`, Refract registers no listener at all. |
| `REFRACT_TRANSPORT` | `sync` | `sync`, `queue` or `null`. See [Transports](#transports). |
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
- Invalid means the default plus one warning. For example: text where a number goes, `0` or a negative number, a `REFRACT_CAPTURE_MAX_BYTES` under 64, an unknown transport, or a boolean where a name goes.
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
- **Tool approvals.** Requests and decisions are `laravel.ai.tool_approval.requested` and `laravel.ai.tool_approval.resolved` events on the run span. A run resumed from approvals records no prompt, since none was sent.
- **Streams.** A stream read to the end gives the same tree as `prompt()`. A stream read again after it stopped is a separate run.
- **Abandoned spans.** A span still open when Refract exports (for example, a stream the app stopped reading) is closed as abandoned. It has no OTel status and the attribute `laravel.ai.abandoned=true`. Langfuse shows it as a warning with the message `abandoned`.
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

A batch is sent in parts of at most 4 MB of OTLP JSON, counted before gzip, so a destination with a body size limit does not refuse it. A big batch usually comes from content capture. Parts are cut between run trees (a run and every span under it). A run tree is cut only when it alone is over 4 MB. A single span over 4 MB is sent alone and one warning is logged: the destination may refuse it.

With `sync`, the parts are sent one after another. A failed part gives its own warning and does not stop the next parts. With `queue`, each part is its own job, so a retry never sends a part again that already got through.

## Transports

The transport decides when and in which process traces are exported. No transport makes a network call before the response is sent.

| Transport | What it does |
| --- | --- |
| `sync` | Exports in the same process: after the response is sent, at the end of a queued job, or at the end of a console command. No retry. |
| `queue` | Pushes one job per batch, or per part of a big batch (see [Request Size](#request-size)). A queue worker exports it. |
| `null` | Discards the spans. |

A sync-driver job or an `Artisan::call()` inside a web request does not export on its own. Its spans leave with the request.

### `queue`

The job goes to your default queue connection and its default queue.

- A job is pushed only when the connection's driver is `redis`, `database`, `sqs` or `beanstalkd`. Every other driver (`sync`, `deferred`, `background`, `failover`, custom) exports with `sync` instead.
- The batch is gzipped. When it, or one part of it, is still too big for a queue message (256 KB minus room for the job envelope), that batch or part is exported with `sync` instead and one warning is logged.
- When the push fails, the batch is exported with `sync` and one warning is logged.
- The job tries 3 times when the destination cannot be reached or answers 408, 429 or 5xx. It waits 10 seconds before the second try and 60 seconds before the third.
- On a 429 or 503 with `Retry-After` in whole seconds, the job waits that long instead, at most 300 seconds. An HTTP date, a negative number or text is ignored. `sync` never waits or retries.
- When the job gives up, it logs one warning. It does not throw, so nothing goes to your error tracker or the `failed_jobs` table.

### Export Failures

| Result | `sync` | `queue` |
| --- | --- | --- |
| 2xx | Done | Done |
| 2xx whose `partialSuccess` refuses spans or has a message | Done, one warning | Done, one warning |
| Network error, 408, 429, 5xx | Dropped, one warning | Retried (3 tries, 10 s then 60 s apart, or the `Retry-After` seconds of a 429 or 503, at most 300 s), then one warning |
| Any other status (3xx, 400, 401, 403, 404, ...) | Dropped, one warning | Dropped, one warning |

A rejected batch's warning names the status and the first 200 characters of the response body. Credentials the destination echoes in the body are masked as `[removed]`: values under key names that contain `key`, `token`, `secret`, `auth`, `password`, `passwd`, `credential` or `cookie` (in JSON, in header lines and in `key=value` pairs), and `Bearer` and `Basic` tokens. Other text, such as a plain error line, is kept.

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
- **Tool results.** A string is recorded as is. Arrays and Collections are recorded in full as JSON. A top-level `Stringable` result is cast with `__toString()`, as the SDK does. Inside an array or Collection, nested arrays and Collections are walked, backed enums keep their value (a pure enum makes the value `[not encodable as JSON]`), files become `[file]`, and any other object is recorded as its class name, with none of its methods run. Any other top-level object is recorded as its class name too. A value that cannot be encoded as JSON is recorded as `[not encodable as JSON]`, with one warning.
- **No built-in redaction.** Refract does not look for secrets. Use a `mask`.

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
- **No extra calls.** Refract never calls agent methods that run your code (`instructions()`, `tools()`) and runs no queries. A run is named without calling the agent's `name()`. To name a tool span, Refract reads the tool name the way the SDK does, which calls `name()` once per tool call on a tool or an agent used as a tool, the same call the SDK makes.
- **Warnings.** Each warning is logged at the `warning` level, prefixed `[refract]`, once per kind per process. After 10 different warnings, Refract stays silent.
- **Bounded memory.** The buffer holds at most 1,000 spans per request, job or command. Past that, new spans are dropped and one warning is logged.

## Known Limitations

- **(L12)** A long command exports its spans only when it ends. They cannot be sent earlier.
- **(L17)** Laravel Octane is not supported.
- **(X10)** With a morph map, the participant type is the full class name, so it does not match the `participant_type` column of the `agent_conversations` table (which holds the alias).
- **(P12)** With no `mask` set, a secret inside captured content is sent as is.

## Contributing

Run the tests, the code style check and static analysis:

```bash
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The workbench runs real scenarios and reads the traces back from Langfuse. Copy `workbench/.env.example` to `workbench/.env`, add the keys of a Langfuse project you use for testing, then:

```bash
composer build
vendor/bin/testbench refract:check R1
```

`refract:check` runs the scenario for a row, reads the trace from Langfuse, and compares it to `workbench/expected/{row}.txt`. It prints `PASS` or `FAIL`.

## License

Refract is open-sourced software licensed under the [MIT license](LICENSE).

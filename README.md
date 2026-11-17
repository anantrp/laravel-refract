# Laravel Refract

OpenTelemetry exports for the Laravel AI SDK with pluggable observability adapters.

Refract records every [Laravel AI SDK](https://github.com/laravel/ai) agent run as OpenTelemetry spans. It sends them as OTLP JSON over HTTP to [Langfuse](https://langfuse.com) or to any OTLP backend.

Install it, set a few environment variables, and you are done. There is no API to learn and no code to change.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- `laravel/ai` 1.4 or higher

Refract uses `open-telemetry/api` only. It does not need the OpenTelemetry SDK.

Laravel Octane is not supported in 0.3.

## Installation

```bash
composer require anantrp/laravel-refract
```

The service provider is discovered automatically. Refract does nothing until you tell it where to send traces. Until then it logs one warning per process and records nothing.

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
REFRACT_OTLP_ENDPOINT=https://otlp.example.com/v1/traces
REFRACT_OTLP_HEADERS="x-api-key=your-key"
```

`otlp` is the default destination, so the first line is optional. The endpoint is the full URL traces are posted to.

Run any agent. Each run is recorded as a tree of spans. A run starts a new trace, or joins your app's active OpenTelemetry trace when there is one. A sub-agent run nests inside the run that called it.

Traces are sent from the process that ran the agent, after the response. To send them from your queue workers instead, with retries, see [Collecting and Draining](#collecting-and-draining).

## Configuration

All settings come from environment variables.

| Variable | Default | What it does |
| --- | --- | --- |
| `REFRACT_ENABLED` | `true` | When `false`, Refract registers no listener at all. |
| `REFRACT_DESTINATION` | `otlp` | `otlp` or `langfuse`. See [Destinations](#destinations). |
| `REFRACT_OTLP_ENDPOINT` | none | `otlp`: the full URL traces are posted to. |
| `REFRACT_OTLP_HEADERS` | none | `otlp`: headers sent with every request. |
| `LANGFUSE_BASE_URL` | Langfuse Cloud | `langfuse`: your Langfuse host. |
| `LANGFUSE_PUBLIC_KEY` | none | `langfuse`: required. |
| `LANGFUSE_SECRET_KEY` | none | `langfuse`: required. |
| `REFRACT_DRAIN` | `sync` | `sync` or `queue`. See [Collecting and Draining](#collecting-and-draining). |
| `REFRACT_COLLECTOR` | `redis` | `redis` or `database`, used when `REFRACT_DRAIN=queue`. |
| `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` | `false` | Record prompts, outputs and tool data. See [Content Capture](#content-capture). |

The service name is your app's name (`APP_NAME`, or `Laravel` when it is empty). The environment is your app's environment (`APP_ENV`).

Rules for every variable:

- Missing or empty (`FOO=`) means the default, with no warning.
- Invalid means the default plus one warning. For example: an unknown destination, drain or collector, or a boolean where a name goes.
- Invalid headers mean no headers from that variable, plus one warning.
- A destination that cannot send logs one warning, and Refract registers nothing, as if `REFRACT_ENABLED=false`. That is an `otlp` destination with no endpoint, a `langfuse` destination with a missing key, or an invalid `REFRACT_OTLP_ENDPOINT` or `LANGFUSE_BASE_URL`. Refract never falls back to another host, so your keys and headers never go to a host you did not name.

Everything works with `php artisan config:cache`.

### Publishing the Config

```bash
php artisan vendor:publish --tag=refract-config
```

This writes `config/refract.php`. Two settings live only in this file: the `Context` attribute map (`context.attributes`) and the content `mask` (`capture.mask`). The connection each collector uses is set there too (`collectors.redis.connection`, `collectors.database.connection`).

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
| `session.id`, `gen_ai.conversation.id` | `invoke_agent`, from the SDK conversation id. A run that is not saved can get `session.id` from a [Context mapping](#context-attributes). |
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

```ini
REFRACT_OTLP_ENDPOINT=https://otlp.example.com/v1/traces
REFRACT_OTLP_HEADERS="x-api-key=abc123,x-team=my%20team"
```

`REFRACT_OTLP_ENDPOINT` is the full traces URL, used as is. Headers use the OpenTelemetry format, with URL-encoded values.

The standard `OTEL_EXPORTER_OTLP_*` variables are not read. They belong to your app's own OpenTelemetry exporter, and its credentials stay with it.

### `langfuse`

```ini
REFRACT_DESTINATION=langfuse
LANGFUSE_BASE_URL=https://us.cloud.langfuse.com
LANGFUSE_PUBLIC_KEY=pk-lf-...
LANGFUSE_SECRET_KEY=sk-lf-...
```

- An empty `LANGFUSE_BASE_URL` means Langfuse Cloud.
- A missing key logs one warning, and Refract records nothing.
- Refract posts to `/api/public/otel/v1/traces` with Basic auth from your keys.
- The environment name is changed to fit Langfuse: lowercase, anything but letters, digits, `-` and `_` becomes `-`, at most 40 characters. `Staging EU 1` becomes `staging-eu-1`. An empty result becomes `default`.
- Langfuse names tool observations by the tool name, so `execute_tool LookupOrder` shows as `LookupOrder`.
- Langfuse stores times in milliseconds. Spans that start in the same millisecond are moved to distinct milliseconds, so they keep their order. Parents still cover their children and events stay inside their span.
- `user.id` is set from a user participant: the participant type must implement `Illuminate\Contracts\Auth\Authenticatable`. A user participant wins over a `user.id` from a `Context` mapping. A run with no user participant, such as a `Team` participant or a run that is not saved, keeps the mapped `user.id`, if any.
- A `Context` mapping never sets a `langfuse.*` attribute. Refract sets those itself, for example on abandoned spans.

### Request Size

Refract packs whole run trees (a run and every span under it, sub-agent runs included) into requests of at most 4 MB of OTLP JSON, counted before gzip. So a destination with a body size limit does not refuse them. A big tree usually comes from content capture. A run tree over 4 MB alone is the exception: its spans are packed one by one, so the tree is split over several requests, with one warning. A single span over 4 MB is sent alone, with one warning: the destination may refuse it.

Every request is gzipped and sent with `Content-Encoding: gzip`.

On Langfuse, spans that start in the same millisecond are moved apart. A run tree split over several requests is moved apart as a whole before it is split, so its siblings stay in order across requests and later drains. Two top-level runs of one trace sent in different requests can still share a millisecond.

## Sending

Refract hands a run tree on once its top-level run ends. Nothing touches the network before a web response is sent.

- **Web request:** every finished run tree is handed on once, after the response is sent. Runs that are still open then are closed as abandoned and handed on with them.
- **Console process** (command, queue worker, tinker): each run tree is handed on when its top-level run ends. The end of an async queued job and the end of the outermost command close the runs still open as abandoned, and hand them on.
- **Inside a database transaction:** in a console process, a run tree that ends while a `DB::transaction()` is open, on any connection the process has used, is handed on after the transaction ends, committed or rolled back. So an export never holds your transaction and its locks open, and a stored tree or drain job is never rolled back with your work. If Refract cannot see the transaction end, the tree leaves with the next run that ends outside a transaction, or at the end of the job or command at the latest, even if a transaction is still open then.

A sync-driver job or an `Artisan::call()` inside a web request does not hand on its own runs. Its spans leave with the request.

Where a run tree goes depends on `REFRACT_DRAIN`:

- **`sync`** (the default): the tree is exported in the same process, once. It is best effort: the export takes at most 5 seconds in all, however many requests it makes, and a tree that cannot be exported in that time is dropped with one warning. It is never tried again. There is nothing to set up.
  After an export fails (network error, timeout, 408, 429 or 5xx), exports pause: for 30 seconds, doubling on each failure in a row up to 5 minutes. While paused, trees are dropped at once, without a request, with one warning. Then one export, in one process, tries the destination again, and an answer ends the pause for every process; the others keep dropping while it tries. The pause is kept in your default cache store, so PHP-FPM requests and queue workers share it. If the cache fails, or cannot save a pause (a full disk, a full Redis), each process keeps the last pause it saw.
- **`queue`**: the tree is stored in a collector, and your queue workers export it, with retries. See [Collecting and Draining](#collecting-and-draining).

Refract sends with an HTTP client of its own, not the `Http` facade. Your global HTTP middleware and options never apply to it, `Http::fake()` in your tests never sees it, and Telescope, Pulse and Nightwatch never record an export.

### Export Failures

| Result | `sync` | `queue` |
| --- | --- | --- |
| 2xx | Done | Done |
| 2xx whose `partialSuccess` refuses spans or has a message | Done, one warning | Done, one warning |
| Network error, 408, 429, 5xx | Dropped, one warning. The later requests of that export are not tried. | Kept and tried again later, one warning. The drain stops until then. |
| Any other status (3xx, 400, 401, 403, 404, ...) | Dropped, one warning | Dropped, one warning |

A rejected batch's warning names the status and the first 200 characters of the response body. Refract masks credentials that the destination echoes in the body as `[removed]`. It masks `Bearer` and `Basic` tokens. It also masks values under key names that contain `key`, `token`, `secret`, `auth`, `password`, `passwd`, `credential` or `cookie`, in JSON, in header lines and in `key=value` pairs. Other text, such as a plain error line, is kept.

A 2xx can carry an OTLP `partialSuccess` (JSON answers only). When it refuses spans or has a message, one warning names the refused count and the message, masked the same way. The answer does not say which spans were refused. They are not retried, as the OTLP spec asks. An empty body, `{}` or a body that is not JSON logs nothing.

Redirects are never followed, so your keys and headers never go to another host. A 3xx is dropped like any other rejected status. The HTTP timeout is 5 seconds with `sync` (for the whole export) and 15 seconds per request from the queue drain. Connecting to the destination may take at most 1 second with `sync` and 5 seconds from the queue drain, so a host that never answers a connection costs 1 second, not 5.

### Lost Traces

Every run tree Refract drops is counted by reason, in your default cache store, and fires an `Anantrp\Refract\Events\TracesDropped` event with the `count` and the `reason`:

| Reason | What happened |
| --- | --- |
| `paused` | `sync`: dropped without a request, while exports pause after a failure |
| `unavailable` | `sync`: a network error, timeout, 408, 429 or 5xx |
| `rejected` | Any other status the destination answered with |
| `unfinished` | `sync`: the export used its 5 seconds before sending these trees |
| `error` | Refract failed to export or store the trees |
| `full` | `queue`: the collector was at its cap |
| `transaction` | `queue`: the database collector was asked to write inside a transaction open on its connection |
| `encode`, `decode` | `queue`: a tree could not be stored, or a stored tree could not be read |
| `run_cap` | One run passed 1,000 spans. Here `count` is the spans it dropped, not trees. |

```php
use Anantrp\Refract\Events\TracesDropped;
use Illuminate\Support\Facades\Event;

Event::listen(function (TracesDropped $event) {
    // Send $event->count and $event->reason to your metrics.
});
```

A listener that throws is ignored, and a drop inside a listener does not fire the event again.

## Collecting and Draining

With `REFRACT_DRAIN=queue`, a finished run tree is not exported where it ran. It is pushed to a collector, a bounded store, and a `DrainTraces` job exports it from your own queue workers. There is no extra daemon.

```ini
REFRACT_DRAIN=queue
REFRACT_COLLECTOR=redis
```

Run a worker that reads the `refract` queue of your default queue connection:

```bash
php artisan queue:work --queue=default,refract
```

It needs:

- **An async queue connection.** On the `sync` connection the drain runs at once, inside the push, and cannot wait before a retry: Refract logs one warning.
- **A cache store shared by every process.** The drain job is unique while it waits, and the backoff after a failure is shared, both through your default cache store. The `array` store, or a `file` store on more than one server, does not share them.

How it works:

- The first push after the store went idle dispatches one drain job, 5 seconds later, so a burst of runs leaves together. Later pushes do not dispatch another while it waits.
- The drain claims about 4 MB of trees, exports them in one request, removes them from the store, and repeats until the store is empty. It starts no new claim after 20 seconds, and hands over to a new drain job.
- The drain ends within 45 seconds, inside its 60 second job `timeout`, which is itself inside the 90 second `retry_after` of Laravel's default queue connections. Each request waits at most what is left of the 45 seconds, and a request is not started with less than a second left: the trees not sent go back to the store for the next drain, which is dispatched at once. A big tree cut part way through its requests keeps its progress: only its unsent spans go back, so the next drain picks up where this one stopped. Keep your queue connection's `retry_after` above 60 seconds.
- On a network error, 408, 429 or 5xx, the drain gives the claimed trees back and stops. The next drain waits 10, then 60, then 300 seconds after each failure in a row, or the `Retry-After` of the answer when it is longer, at most 300 seconds. A tree is tried again until it is sent: there is no attempt limit. The store cap bounds what waits.
- If the destination settings are missing or invalid in the worker, the drain logs one warning and, while trees are stored, tries again with the same backoff. On an empty store it dispatches nothing.
- The drain job never throws, and it has no attempt limit (`$tries = 0`), so nothing reaches `failed_jobs`: a drain whose worker dies, or that is killed at its timeout, is given to a worker again after the connection's `retry_after`.
- A claimed tree is leased for 120 seconds, twice the job `timeout`, so a running drain never loses its trees to another. If the worker dies, the tree is claimed again when the lease runs out.

Delivery is at least once. A crash between the export and the removal from the store, or a retryable failure in the middle of a batch, sends trees again. Span ids are set when a span is recorded, so backends deduplicate a resent tree by span id.

The store holds at most 64 MB of trees. At the cap, new trees are dropped and counted, with one warning. The trees already stored are kept.

### Redis Collector

```ini
REFRACT_COLLECTOR=redis
```

Trees are kept in a Redis stream, on your default Redis connection, or the one named in `collectors.redis.connection` in the published config.

- It needs Redis 6.2 or higher (`XAUTOCLAIM`), and the `phpredis` extension or `predis/predis`.
- Use a connection that never evicts keys. Under an `allkeys-*` eviction policy, Redis can drop stored traces, or the store's byte count, without a warning.
- On Redis Cluster, every key shares the hash tag `{traces}`, so they live on one node.

### Database Collector

```ini
REFRACT_COLLECTOR=database
```

Trees are kept in a `refract_traces` table. Publish the migration and run it:

```bash
php artisan vendor:publish --tag=refract-migrations
php artisan migrate
```

- The migration and the collector use your default database connection, or the one named in `collectors.database.connection` in the published config.
- Give the collector a connection of its own. Refract never stores a tree inside a transaction that is open on the collector's connection: it would be rolled back with your work, and an error there could abort it. A run that ends inside a transaction is handed on after it (see [Sending](#sending)); a tree that still meets an open transaction on that connection is dropped with one warning.
- Due trees are claimed in the order they became due, so a tree given back after a failure waits behind the trees that were already due.
- On MySQL 8+, MariaDB 10.6+ and PostgreSQL, a claim uses `SELECT ... FOR UPDATE SKIP LOCKED`, so two drains never claim the same tree. SQLite has no `SKIP LOCKED`: a claim is a plain transaction, which is fine for one worker.

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

- **Byte cap.** Each value is cut at 128 KB, at a character border, and marked with its original size: `…[cut, original size N bytes]`.
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

The participant is who the run is for. A saved run with a participant (`->forUser($user)`, `->forParticipant($team)`, `continue($id, as: $user)`) records the conversation participant as `laravel.ai.participant.type` and `laravel.ai.participant.id`. The type is always the full class name, for example `App\Models\User`.

A run that is not saved, or saved with no participant, records no participant. To say who it is for, map Laravel `Context` keys to `user.id` and `session.id` (see [Context Attributes](#context-attributes)):

```php
// config/refract.php
'attributes' => [
    'user_id' => 'user.id',
    'thread' => 'session.id',
],
```

```php
use Illuminate\Support\Facades\Context;

Context::add('user_id', $user->id);
```

A saved run keeps its own values: its conversation id wins over a mapped `session.id`, and on Langfuse a user participant wins over a mapped `user.id`.

### Scheduled Agents

A scheduled agent run often concerns a user who did not start it. Say who it is for in the job:

```php
// Saved run: the conversation participant is used.
WeeklySummaryAgent::make()->forUser($user)->prompt('Summarize my week.');

// Run not saved: set the Context key mapped to user.id.
Context::add('user_id', $user->id);

WeeklySummaryAgent::make()->prompt('Summarize the week.');
```

Who started the run is a separate fact. Send it through the same map:

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
- A mapped key never replaces an attribute Refract sets on the run span, even an empty one. The exception is `session.id`: a mapped key sets it on a run that is not saved.
- A mapped key may set `user.id`. On Langfuse, a user participant wins over it.
- On Langfuse, a mapped key never sets a `langfuse.*` attribute.
- A numeric attribute name, such as `'123'`, is sent as the string `"123"`.

## Safety

- **Refract never breaks your app.** Every listener and lifecycle hook is guarded. A failure inside Refract logs one warning and the run goes on. Your app's own exceptions pass through unchanged.
- **No extra calls.** Refract never calls agent methods that run your code (`instructions()`, `tools()`) and runs no queries while a run records. The only queries are the database collector's, after the response. A run is named without calling the agent's `name()`. To name a tool span, Refract reads the tool name the way the SDK does. This calls `name()` once per tool call, on a tool or on an agent used as a tool. The SDK makes the same call.
- **Bounded retries.** With `REFRACT_DRAIN=queue`, the drain job never throws, so nothing reaches `failed_jobs`, and the store never grows past its cap.
- **Warnings.** Each warning is logged at the `warning` level, prefixed `[refract]`, once per kind per process. After 10 different warnings, Refract stays silent.

### Bounded Memory

- One run records at most 1,000 spans, sub-agent runs included. Past that, Refract drops the run's new spans, logs one warning and marks the run `laravel.ai.truncated=true`. Other runs are not affected.
- A run dropped at the cap stays dropped. Its steps, tools and sub-agent runs are dropped too. So they never show up as traces of their own.
- A console process holds only its open runs: each run tree leaves when it ends. A web request holds its finished run trees until the response is sent.
- A stream that the app stopped reading stays open until the end of the process.
- With `REFRACT_DRAIN=queue`, the collector holds at most 64 MB of trees. At the cap, new trees are dropped with one warning.

## Known Limitations

- Laravel Octane is not supported.
- With a morph map, the participant type is the full class name. So it does not match the `participant_type` column of the `agent_conversations` table, which holds the alias.
- With no `mask` set, a secret inside captured content is sent as is.
- Only agent runs are traced. Embeddings, images, audio and other SDK operations are not.
- With `sync`, the export after the response still runs in the same PHP-FPM worker. When the destination goes down, the first export to find out keeps its worker busy for up to 5 seconds (1 second if the host does not answer a connection). Exports then pause, and cost nothing until the pause ends.
- With `sync`, exports inside a queue job run under that job's `timeout`. When the destination goes down, the first failed export adds up to 5 seconds to the job; later runs add nothing while exports pause.
- With `sync`, a destination that is down or slow loses the traces sent meanwhile: nothing is tried again. Use `REFRACT_DRAIN=queue` to keep them.
- Spans not yet handed on are lost when the worker kills a job at its `timeout`. They are also lost when a command ends by a signal, `exit()` or `dd()`.
- With `REFRACT_DRAIN=queue`, a tree can be sent twice (see [Collecting and Draining](#collecting-and-draining)). Backends that do not deduplicate by span id show it twice.
- With `REFRACT_DRAIN=queue`, a push is a write to Redis or your database after the response. If the store cannot be reached, the trees are dropped with one warning.

## Contributing

Run the tests, the code style check and static analysis:

```bash
composer test
vendor/bin/pint --test
composer analyse
```

The Redis collector tests run against Redis on `127.0.0.1` and are skipped when it cannot be reached (`--exclude-group=redis` leaves them out). The MySQL collector tests run only with `REFRACT_TEST_MYSQL_HOST` set, plus `_PORT`, `_DATABASE`, `_USERNAME` and `_PASSWORD` (`--group=mysql`).

The workbench runs real scenarios and reads the traces back from Langfuse. Copy `workbench/.env.example` to `workbench/.env`, add the keys of a Langfuse project you use for testing, then:

```bash
composer build
vendor/bin/testbench refract:check R1
```

`refract:check` runs the named scenario (one per file in `workbench/expected`), reads the trace from Langfuse, and compares it to `workbench/expected/{scenario}.txt`. It prints `PASS` or `FAIL`.

`L2` runs with `REFRACT_DRAIN=queue`, so it needs Redis and the workbench's database queue: `refract:check L2` waits until the drain job is due, then runs `queue:work --queue=refract --stop-when-empty` before it reads Langfuse.

## License

Refract is open-sourced software licensed under the [MIT license](LICENSE).

<?php

use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;
use Anantrp\Refract\Export\Platforms\Otlp\OtlpPlatform;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When enabled, every Laravel AI SDK agent run is recorded as an
    | OpenTelemetry trace. When disabled, Refract registers nothing.
    |
    */

    'enabled' => env('REFRACT_ENABLED'),

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | The name your application is reported under in every trace. When it is
    | not set, your application's name is used.
    |
    */

    'service_name' => env('OTEL_SERVICE_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The deployment environment every trace is reported under. When it is
    | not set, your application's environment is used.
    |
    */

    'environment' => env('REFRACT_ENVIRONMENT'),

    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | The backend that receives the exported traces: one of the keys under
    | "destinations" below.
    |
    | Supported: "otlp", "langfuse"
    |
    */

    'destination' => env('REFRACT_DESTINATION'),

    /*
    |--------------------------------------------------------------------------
    | Drain
    |--------------------------------------------------------------------------
    |
    | How finished runs reach the destination. "sync" exports them in the
    | process that ran them, after the response: best effort, dropped with
    | one warning when the destination is down. "queue" stores them in the
    | collector and exports them from your queue workers, on the "refract"
    | queue of your default queue connection, retrying with a backoff.
    |
    | Supported: "sync", "queue"
    |
    */

    'drain' => env('REFRACT_DRAIN'),

    /*
    |--------------------------------------------------------------------------
    | Collector
    |--------------------------------------------------------------------------
    |
    | Where the queue drain stores finished runs until a worker exports
    | them, on the connection named under "collectors" (your default one
    | when it is null). "redis" needs a connection that never evicts keys.
    | "database" needs the published migration, and a connection of its
    | own: a run stored inside your DB::transaction() on the same
    | connection is rolled back with it.
    |
    | Supported: "redis", "database"
    |
    */

    'collector' => env('REFRACT_COLLECTOR'),

    'collectors' => [
        'redis' => [
            'connection' => null,
        ],

        'database' => [
            'connection' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | How each export is compressed, for both destinations: "gzip" sends the
    | body gzipped with "Content-Encoding: gzip", "none" sends it plain. It is
    | the only OTEL_EXPORTER_OTLP_* variable that also applies to "langfuse".
    |
    | Supported: "gzip", "none"
    |
    */

    'compression' => env('OTEL_EXPORTER_OTLP_COMPRESSION'),

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | A saved run records its conversation participant. A run that is not
    | saved records the participant from the two Laravel Context keys named
    | under "participant", and only when both are set. The "attributes" map
    | copies Laravel Context keys onto each run span, keyed by Context key.
    |
    */

    'context' => [
        'participant' => [
            'type' => env('REFRACT_CONTEXT_PARTICIPANT_TYPE'),
            'id' => env('REFRACT_CONTEXT_PARTICIPANT_ID'),
        ],

        'attributes' => [
            // 'tenant_id' => 'tenant.id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Capture
    |--------------------------------------------------------------------------
    |
    | When "content" is on, prompts, step messages, outputs, tool arguments
    | and tool results are recorded. It is off by default. Each value is cut
    | at "max_bytes" (default 131072, 128 KB, at least 64) and marked with
    | its original size. Attachments and files are never recorded: a file
    | in a value becomes "[file]".
    |
    | "mask" is the class name of an invokable class, made from the
    | container, that is given every captured string and returns it masked.
    | When it throws, the value is replaced by a fixed placeholder.
    |
    */

    'capture' => [
        'content' => env('OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT'),
        'max_bytes' => env('REFRACT_CAPTURE_MAX_BYTES'),
        'mask' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Destinations
    |--------------------------------------------------------------------------
    |
    | Where each destination sends its traces. "platform" is the class that
    | builds the destination's requests from the rest of its config.
    |
    | "otlp": REFRACT_OTLP_ENDPOINT is the full URL traces are posted to,
    | sent with REFRACT_OTLP_HEADERS only. When it is not set, the standard
    | OTEL_EXPORTER_OTLP_ENDPOINT is used with "/v1/traces" appended, sent
    | with OTEL_EXPORTER_OTLP_HEADERS and REFRACT_OTLP_HEADERS. Headers use
    | the OpenTelemetry format: "key1=value1,key2=value2", URL-encoded values.
    |
    | "langfuse": LANGFUSE_BASE_URL defaults to Langfuse Cloud.
    |
    | An invalid REFRACT_OTLP_ENDPOINT or LANGFUSE_BASE_URL exports nothing,
    | with one warning: there is no fallback host for your keys and headers.
    |
    */

    'destinations' => [

        'otlp' => [
            'platform' => OtlpPlatform::class,
            'endpoint' => env('REFRACT_OTLP_ENDPOINT'),
            'headers' => env('REFRACT_OTLP_HEADERS'),

            'otel' => [
                'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
            ],
        ],

        'langfuse' => [
            'platform' => LangfusePlatform::class,
            'url' => env('LANGFUSE_BASE_URL'),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
        ],

    ],

];

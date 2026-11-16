<?php

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
    | Destination
    |--------------------------------------------------------------------------
    |
    | The backend that receives the exported traces, configured under
    | "destinations" below. It is "otlp" when not set.
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
    | Supported: "sync" (the default), "queue"
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
    | Context
    |--------------------------------------------------------------------------
    |
    | The "attributes" map copies Laravel Context keys onto each run span,
    | keyed by Context key. A key mapped to "user.id" or "session.id" tells
    | your backend who a run is for when the run is not a saved
    | conversation; a saved conversation's own values win.
    |
    */

    'context' => [
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
    | at 128 KB and marked with its original size. Attachments and files are
    | never recorded: a file in a value becomes "[file]".
    |
    | "mask" is the class name of an invokable class, made from the
    | container, that is given every captured string and returns it masked.
    | When it throws, the value is replaced by a fixed placeholder.
    |
    */

    'capture' => [
        'content' => env('OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT'),
        'mask' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Destinations
    |--------------------------------------------------------------------------
    |
    | Where each destination sends its traces.
    |
    | "otlp": REFRACT_OTLP_ENDPOINT is the full URL traces are posted to,
    | sent with REFRACT_OTLP_HEADERS. Headers use the OpenTelemetry format:
    | "key1=value1,key2=value2", with URL-encoded values.
    |
    | "langfuse": LANGFUSE_BASE_URL defaults to Langfuse Cloud.
    |
    | A destination that cannot send (no endpoint, a missing key, an invalid
    | URL) logs one warning and Refract records nothing, as if disabled. An
    | invalid URL never falls back to another host.
    |
    */

    'destinations' => [

        'otlp' => [
            'endpoint' => env('REFRACT_OTLP_ENDPOINT'),
            'headers' => env('REFRACT_OTLP_HEADERS'),
        ],

        'langfuse' => [
            'url' => env('LANGFUSE_BASE_URL'),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
        ],

    ],

];

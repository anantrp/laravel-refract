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
    | Transport
    |--------------------------------------------------------------------------
    |
    | The transport decides when and where recorded spans are exported:
    | "sync" exports after the response is sent, "queue" exports from a
    | queue worker, and "null" discards the spans.
    |
    | Supported: "sync", "queue", "null"
    |
    */

    'transport' => env('REFRACT_TRANSPORT'),

    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | The backend that receives the exported traces.
    |
    | Supported: "otlp", "langfuse"
    |
    */

    'destination' => env('REFRACT_DESTINATION'),

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
    | Destinations
    |--------------------------------------------------------------------------
    |
    | Where each destination sends its traces.
    |
    | "otlp": REFRACT_OTLP_ENDPOINT is the full URL traces are posted to,
    | sent with REFRACT_OTLP_HEADERS only. When it is not set, the standard
    | OTEL_EXPORTER_OTLP_ENDPOINT is used with "/v1/traces" appended, sent
    | with OTEL_EXPORTER_OTLP_HEADERS and REFRACT_OTLP_HEADERS. Headers use
    | the OpenTelemetry format: "key1=value1,key2=value2", URL-encoded values.
    |
    | "langfuse": LANGFUSE_BASE_URL defaults to Langfuse Cloud.
    |
    */

    'destinations' => [

        'otlp' => [
            'endpoint' => env('REFRACT_OTLP_ENDPOINT'),
            'headers' => env('REFRACT_OTLP_HEADERS'),

            'otel' => [
                'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
            ],
        ],

        'langfuse' => [
            'url' => env('LANGFUSE_BASE_URL'),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
        ],

    ],

];

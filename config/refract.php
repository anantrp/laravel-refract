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
    | under "participant", and only when both are set.
    |
    */

    'context' => [
        'participant' => [
            'type' => env('REFRACT_CONTEXT_PARTICIPANT_TYPE'),
            'id' => env('REFRACT_CONTEXT_PARTICIPANT_ID'),
        ],
    ],

    'destinations' => [

        'langfuse' => [
            'url' => env('LANGFUSE_BASE_URL'),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
        ],

    ],

];

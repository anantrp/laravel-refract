<?php

/*
 * Stub OTLP receiver for level 2 evidence. Local only: not in the package, not in CI.
 *
 *   php -S 127.0.0.1:4318 workbench/otlp-stub/router.php
 *
 * Each request adds one JSON line to requests.jsonl (next to this file, or
 * STUB_OTLP_LOG). The answer to request N is entry N-1 of the JSON array in
 * script.json (or STUB_OTLP_SCRIPT): {"status": 429, "headers": {"Retry-After": "2"}, "body": {}}.
 * The body is a string, or JSON that is encoded as is.
 * A missing script or entry answers 200 {}. Delete the log to start again at request 1.
 */

$log = getenv('STUB_OTLP_LOG') ?: __DIR__.'/requests.jsonl';
$script = getenv('STUB_OTLP_SCRIPT') ?: __DIR__.'/script.json';

$wire = (string) file_get_contents('php://input');
$encoding = $_SERVER['HTTP_CONTENT_ENCODING'] ?? null;
$body = $encoding === 'gzip' ? @gzdecode($wire) : $wire;

$spans = null;
$payload = is_string($body) ? json_decode($body, true) : null;

if (is_array($payload)) {
    $spans = 0;

    foreach ($payload['resourceSpans'] ?? [] as $resource) {
        foreach ($resource['scopeSpans'] ?? [] as $scope) {
            $spans += count($scope['spans'] ?? []);
        }
    }
}

$number = is_file($log) ? count(file($log, FILE_SKIP_EMPTY_LINES)) + 1 : 1;

file_put_contents($log, json_encode([
    'n' => $number,
    'time_ms' => (int) floor(microtime(true) * 1000),
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'content_encoding' => $encoding,
    'bytes_wire' => strlen($wire),
    'bytes_decoded' => is_string($body) ? strlen($body) : null,
    'spans' => $spans,
], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

// Decoded as objects, so a scripted {} body is sent back as {}, not [].
$answers = is_file($script) ? json_decode((string) file_get_contents($script)) : null;
$answer = is_array($answers) ? ($answers[$number - 1] ?? null) : null;
$answer = $answer instanceof stdClass ? $answer : new stdClass;

http_response_code((int) ($answer->status ?? 200));
header('Content-Type: application/json');

foreach ($answer->headers ?? [] as $name => $value) {
    header($name.': '.$value);
}

$reply = $answer->body ?? '{}';

echo is_string($reply) ? $reply : json_encode($reply);

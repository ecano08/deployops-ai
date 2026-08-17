<?php

use App\Support\ResourceSanitizer;

it('strips secret keys from integration config', function () {
    $safe = ResourceSanitizer::integrationConfig([
        'timeout' => 10,
        'api_key' => 'secret-value',
        'nested' => ['webhook_secret' => 'also-secret', 'retry_count' => 2],
    ]);

    expect($safe)->toBe([
        'timeout' => 10,
        'nested' => ['retry_count' => 2],
    ]);
});

it('strips secret keys and truncates long metadata values', function () {
    $longValue = str_repeat('x', 600);

    $safe = ResourceSanitizer::metadata([
        'status_code' => 200,
        'token' => 'hidden',
        'body' => $longValue,
    ]);

    expect($safe['status_code'])->toBe(200)
        ->and($safe)->not->toHaveKey('token')
        ->and(strlen($safe['body']))->toBe(503);
});

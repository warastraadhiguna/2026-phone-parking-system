<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->logFile = tempnam(sys_get_temp_dir(), 'log');

    config([
        'logging.default' => 'structured',
        'logging.channels.structured.handler_with.stream' => $this->logFile,
    ]);
    Log::forgetChannel('structured');

    Route::middleware('api')->get('api/v1/_test/log', function () {
        Log::info('Probe for {subject}', [
            'subject' => 'attendant',
            'password' => 'hunter2-plain',
            'refresh_token' => 'rt-secret-value',
            'nested' => ['midtrans_server_key' => 'SB-Mid-server-xyz', 'X-Api-Key' => 'k-123'],
            'headers' => ['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig'],
            'note' => 'client sent Authorization: Bearer abc.def.ghi',
            'amount' => 2000,
        ]);

        return response()->noContent();
    });
});

afterEach(fn () => @unlink($this->logFile));

/** @return array<string, mixed> */
function lastLogRecord(string $file): array
{
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

it('writes one JSON object per line containing the request id', function () {
    $response = $this->get('/api/v1/_test/log');

    $record = lastLogRecord($this->logFile);

    expect($record)
        ->toHaveKeys(['message', 'context', 'level_name', 'datetime', 'extra'])
        ->and($record['message'])->toBe('Probe for attendant')
        ->and($record['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($record['context']['amount'])->toBe(2000);
});

it('redacts sensitive fields at any depth', function () {
    $this->get('/api/v1/_test/log');

    $raw = (string) file_get_contents($this->logFile);
    $record = lastLogRecord($this->logFile);

    expect($record['context']['password'])->toBe('[REDACTED]')
        ->and($record['context']['refresh_token'])->toBe('[REDACTED]')
        ->and($record['context']['nested']['midtrans_server_key'])->toBe('[REDACTED]')
        ->and($record['context']['nested']['X-Api-Key'])->toBe('[REDACTED]')
        ->and($record['context']['headers']['Authorization'])->toBe('[REDACTED]')
        ->and($record['context']['note'])->toBe('client sent Authorization: Bearer [REDACTED]');

    expect($raw)
        ->not->toContain('hunter2-plain')
        ->not->toContain('rt-secret-value')
        ->not->toContain('SB-Mid-server-xyz')
        ->not->toContain('eyJhbGciOiJIUzI1NiJ9')
        ->not->toContain('abc.def.ghi');
});

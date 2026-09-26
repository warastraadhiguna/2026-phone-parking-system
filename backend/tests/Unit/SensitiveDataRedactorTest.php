<?php

use App\Support\Logging\SensitiveDataRedactor;

it('treats credential-like keys as sensitive regardless of style', function (string $key) {
    expect((new SensitiveDataRedactor)->isSensitiveKey($key))->toBeTrue();
})->with([
    'password', 'PASSWORD', 'password_confirmation', 'new-password', 'pin',
    'token', 'access_token', 'accessToken', 'refresh_token', 'X-Xsrf-Token',
    'authorization', 'Cookie', 'client_secret', 'api_key', 'X-Api-Key',
    'server_key', 'midtrans_server_key', 'private_key', 'signature_key',
]);

it('keeps ordinary operational keys', function (string $key) {
    expect((new SensitiveDataRedactor)->isSensitiveKey($key))->toBeFalse();
})->with([
    'amount', 'transaction_uuid', 'request_id', 'attendant_id', 'status', 'vehicle_type', 'keyboard',
]);

it('redacts bearer and basic credentials inside free text', function () {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactString('Authorization: Bearer abc.DEF-123_x'))->toBe('Authorization: Bearer [REDACTED]')
        ->and($redactor->redactString('basic dXNlcjpwYXNz'))->toBe('basic [REDACTED]');
});

it('bounds recursion depth', function () {
    $deep = ['v' => 'x'];
    for ($i = 0; $i < 20; $i++) {
        $deep = ['n' => $deep];
    }

    expect(json_encode((new SensitiveDataRedactor)->redact($deep)))->toContain('[REDACTED]');
});

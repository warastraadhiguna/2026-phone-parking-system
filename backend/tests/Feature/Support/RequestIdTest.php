<?php

use Illuminate\Support\Str;

it('generates a request id when the client sends none', function () {
    $response = $this->getJson('/api/v1/health/live');

    $id = $response->headers->get('X-Request-Id');

    expect($id)->not->toBeNull()
        ->and(Str::isUuid($id))->toBeTrue();
    $response->assertJsonPath('meta.request_id', $id);
});

it('reuses a well-formed incoming request id', function () {
    $this->getJson('/api/v1/health/live', ['X-Request-Id' => 'android-7f3a9c21-0001'])
        ->assertHeader('X-Request-Id', 'android-7f3a9c21-0001')
        ->assertJsonPath('meta.request_id', 'android-7f3a9c21-0001');
});

it('replaces a malformed incoming request id', function (string $malformed) {
    $response = $this->getJson('/api/v1/health/live', ['X-Request-Id' => $malformed]);

    $id = $response->headers->get('X-Request-Id');

    expect($id)->not->toBe($malformed)
        ->and(Str::isUuid($id))->toBeTrue();
})->with([
    'too short' => 'abc',
    'log injection' => "abcdefgh\nFAKE LOG LINE",
    'markup' => '<script>alert(1)</script>',
    'too long' => str_repeat('a', 129),
]);

it('adds the request id to error responses', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()
        ->assertJsonPath('meta.request_id', $response->headers->get('X-Request-Id'));
});

it('adds the request id to admin web responses', function () {
    $this->withoutVite()->get('/')->assertHeader('X-Request-Id');
});

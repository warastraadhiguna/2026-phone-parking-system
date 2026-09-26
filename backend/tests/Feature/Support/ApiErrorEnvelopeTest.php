<?php

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Throwaway routes that exercise the global exception mapping.
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('validation', fn (Request $request) => $request->validate(['plate' => ['required', 'string']]));
        Route::get('domain-error', fn () => throw new ApiException(ErrorCode::SHIFT_NOT_ACTIVE));
        Route::get('crash', fn () => throw new RuntimeException('db password is pati_local_dev_only'));
        Route::get('forbidden', fn () => abort(403));
        Route::get('payload-too-large', fn () => abort(413));
    });
});

it('uses the standard envelope for every error', function (string $method, string $uri, int $status, string $code) {
    $this->json($method, $uri)
        ->assertStatus($status)
        ->assertHeader('X-Request-Id')
        ->assertExactJsonStructure(['success', 'data', 'meta' => ['request_id'], 'error' => ['code', 'message']])
        ->assertJson(['success' => false, 'data' => null, 'error' => ['code' => $code]]);
})->with([
    'unknown route' => ['GET', '/api/v1/nope', 404, 'NOT_FOUND'],
    'wrong method' => ['POST', '/api/v1/health/live', 405, 'METHOD_NOT_ALLOWED'],
    'domain error' => ['GET', '/api/v1/_test/domain-error', 409, 'SHIFT_NOT_ACTIVE'],
    'forbidden' => ['GET', '/api/v1/_test/forbidden', 403, 'FORBIDDEN'],
    'other 4xx' => ['GET', '/api/v1/_test/payload-too-large', 413, 'BAD_REQUEST'],
    'unexpected exception' => ['GET', '/api/v1/_test/crash', 500, 'INTERNAL_ERROR'],
]);

it('returns field errors for validation failures', function () {
    $this->postJson('/api/v1/_test/validation', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['plate']]]]);
});

it('uses the error code default message for domain errors', function () {
    $this->getJson('/api/v1/_test/domain-error')
        ->assertJsonPath('error.message', 'Active shift is required.');
});

it('never exposes internal exception details, even with debug enabled', function () {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/v1/_test/crash');

    $response->assertStatus(500)->assertJsonPath('error.message', 'An unexpected error occurred.');
    expect($response->getContent())
        ->not->toContain('pati_local_dev_only')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});

it('renders API errors as JSON even without an Accept header', function () {
    $this->get('/api/v1/nope')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

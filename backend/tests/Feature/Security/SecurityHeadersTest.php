<?php

/*
| Phase 11: browser security headers on the admin web.
*/

it('sends a strict content security policy on admin pages', function () {
    config(['reporting.map_tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png']);

    $csp = $this->get('/login')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self'")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain('img-src \'self\' data: blob: https://tile.openstreetmap.org');
});

it('allows only points (no tile host) when no tile URL is configured', function () {
    config(['reporting.map_tile_url' => '']);

    expect($this->get('/login')->headers->get('Content-Security-Policy'))->toContain("img-src 'self' data: blob:;");
});

it('adds HSTS on HTTPS outside local development', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

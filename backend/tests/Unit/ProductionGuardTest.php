<?php

use App\Support\Security\ProductionGuard;

it('refuses debug output or plain HTTP in production', function () {
    expect(fn () => ProductionGuard::assertSafe('production', true, 'https://parkir.example.invalid'))->toThrow(RuntimeException::class, 'APP_DEBUG')
        ->and(fn () => ProductionGuard::assertSafe('production', false, 'http://parkir.example.invalid'))->toThrow(RuntimeException::class, 'https');

    expect(fn () => ProductionGuard::assertSafe('production', false, 'https://parkir.example.invalid', 'sqlite'))->toThrow(RuntimeException::class, 'pgsql');
    ProductionGuard::assertSafe('production', false, 'https://parkir.example.invalid', 'pgsql');
    ProductionGuard::assertSafe('local', true, 'http://localhost:8080');
    expect(true)->toBeTrue();
});

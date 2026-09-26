<?php

use Inertia\Testing\AssertableInertia as Assert;

it('serves the admin login page through Inertia with shared props', function () {
    $this->withoutVite()
        ->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login') // also asserts resources/js/Pages/Auth/Login.tsx exists
            ->where('app.name', config('app.name'))
            ->where('app.environment', 'testing')
            ->where('auth.user', null));
});

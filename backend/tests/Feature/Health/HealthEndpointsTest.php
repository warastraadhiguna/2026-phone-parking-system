<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/** Point a dependency at a closed port so connecting fails fast with "connection refused". */
function breakDatabase(): void
{
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');
}

function breakRedis(): void
{
    config(['database.redis.default.port' => 1, 'database.redis.health.port' => 1]);
    Redis::purge('default');
    Redis::purge('health');
}

describe('GET /api/v1/health/live', function () {
    it('reports the process as alive', function () {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJsonStructure(['success', 'data' => ['status'], 'meta' => ['request_id'], 'error'])
            ->assertJson(['success' => true, 'data' => ['status' => 'alive'], 'error' => null]);
    });

    it('stays alive while PostgreSQL and Redis are unavailable', function () {
        breakDatabase();
        breakRedis();

        $this->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('data.status', 'alive');
    });
});

describe('GET /api/v1/health/ready', function () {
    it('is ready when PostgreSQL and Redis are reachable', function () {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ready',
                    'checks' => ['database' => ['status' => 'ok'], 'redis' => ['status' => 'ok']],
                ],
                'error' => null,
            ]);
    });

    it('returns 503 when PostgreSQL is unavailable', function () {
        breakDatabase();

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'details' => ['checks' => ['database' => ['status' => 'fail'], 'redis' => ['status' => 'ok']]],
                ],
            ]);
    });

    it('returns 503 when Redis is unavailable', function () {
        breakRedis();

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.details.checks.database.status', 'ok')
            ->assertJsonPath('error.details.checks.redis.status', 'fail');
    });

    it('does not leak connection details when a dependency fails', function () {
        breakDatabase();

        $body = $this->getJson('/api/v1/health/ready')->getContent();

        expect($body)
            ->not->toContain('pati_local_dev_only')
            ->not->toContain('SQLSTATE')
            ->not->toContain('postgres');
    });
});

<?php

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\Role;
use App\Support\Database\AppendOnlyTable;
use App\Support\RequestContext\RequestContext;
use App\Support\RequestId\RequestId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => syncRoles());

function recordAudit(array $metadata = []): AuditLog
{
    return app(RecordAuditEvent::class)->handle(AuditAction::USER_CHANGED, staffUser(Role::SUPER_ADMIN), 'user', 42, $metadata);
}

it('records who, what, where and the correlation id', function () {
    RequestId::set('audit-test-0001');
    RequestContext::setClientIp('10.1.2.3');
    RequestContext::setDeviceUuid(TEST_DEVICE_UUID);

    $log = recordAudit(['changes' => ['name' => ['from' => 'A', 'to' => 'B']]])->refresh();

    expect($log->actor_type)->toBe(ActorType::USER)
        ->and($log->actor_label)->not->toBeNull()
        ->and($log->action)->toBe('USER_CHANGED')
        ->and($log->entity_type)->toBe('user')
        ->and($log->entity_id)->toBe('42')
        ->and($log->metadata)->toBe(['changes' => ['name' => ['to' => 'B', 'from' => 'A']]])
        ->and($log->ip_address)->toBe('10.1.2.3')
        ->and($log->device_uuid)->toBe(TEST_DEVICE_UUID)
        ->and($log->request_id)->toBe('audit-test-0001')
        ->and($log->occurred_at)->not->toBeNull();
});

it('records system events without an actor', function () {
    $log = app(RecordAuditEvent::class)->handle(AuditAction::ROLE_PERMISSIONS_SYNCED);

    expect($log->actor_type)->toBe(ActorType::SYSTEM)->and($log->actor_id)->toBeNull();
});

it('never stores secrets even if a caller passes them', function () {
    $log = recordAudit(['password' => 'hunter2-plain', 'nested' => ['refresh_token' => 'rt-secret']])->refresh();

    expect($log->metadata)->toBe(['nested' => ['refresh_token' => '[REDACTED]'], 'password' => '[REDACTED]']);
});

it('cannot be changed or deleted through the model', function () {
    $log = recordAudit();

    expect(fn () => $log->update(['action' => 'LOGIN']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('cannot be changed, deleted or truncated in the database', function (string $statement) {
    recordAudit();

    expect(fn () => DB::transaction(fn () => DB::statement($statement)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe(AppendOnlyTable::SQLSTATE));

    expect(AuditLog::query()->where('action', 'USER_CHANGED')->count())->toBe(1);
})->with([
    'update' => "UPDATE audit_logs SET action = 'TAMPERED'",
    'delete' => 'DELETE FROM audit_logs',
    'truncate' => 'TRUNCATE audit_logs',
]);

it('enforces actor consistency in the database', function () {
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->insert([
        'actor_type' => 'USER', 'actor_id' => null, 'action' => 'LOGIN',
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
});

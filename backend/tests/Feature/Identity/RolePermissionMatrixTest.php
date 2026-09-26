<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Actions\SyncRolePermissions;
use App\Domain\Identity\Enums\Permission as P;
use App\Domain\Identity\Enums\Role;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

describe('code-defined matrix', function () {
    it('grants every permission to at least one role', function () {
        $granted = collect(Role::cases())->flatMap(fn (Role $r) => $r->permissions())->unique();

        expect($granted)->toHaveCount(count(P::cases()));
    });

    it('keeps mobile permissions for attendants only', function () {
        foreach (Role::cases() as $role) {
            $mobile = array_filter($role->permissions(), fn (P $p) => $p->isMobile());

            expect(count($mobile) === count($role->permissions()))->toBe($role === Role::PARKING_ATTENDANT, $role->value);
            if ($role !== Role::PARKING_ATTENDANT) {
                expect($mobile)->toBeEmpty();
            }
        }
    });

    it('keeps Auditor and Executive Viewer read-only', function (Role $role) {
        foreach ($role->permissions() as $permission) {
            expect($permission->isReadOnly())->toBeTrue("{$role->value} has {$permission->value}");
        }
    })->with([Role::AUDITOR, Role::EXECUTIVE_VIEWER]);

    it('separates duties around money', function () {
        $holders = fn (P $p) => collect(Role::cases())->filter(fn (Role $r) => in_array($p, $r->permissions(), true))->values()->all();

        expect($holders(P::SETTLEMENTS_VERIFY))->toBe([Role::FINANCE])
            ->and($holders(P::PAYMENTS_REFUND_RECORD))->toBe([Role::FINANCE])
            ->and($holders(P::RECONCILIATION_RUN))->toBe([Role::FINANCE])
            ->and($holders(P::TRANSACTIONS_VOID_APPROVE))->toBe([Role::SUPERVISOR])
            ->and($holders(P::ADJUSTMENTS_APPROVE))->toBe([Role::SUPERVISOR])
            ->and($holders(P::USERS_MANAGE))->toBe([Role::SUPER_ADMIN])
            ->and($holders(P::PAYMENT_CONFIGURE))->toBe([Role::SUPER_ADMIN]);

        expect(in_array(P::TRANSACTIONS_VOID_REQUEST, Role::SUPERVISOR->permissions(), true))->toBeFalse('requester must not approve');
    });
});

describe('sync to the database', function () {
    it('creates every role and permission', function () {
        $result = app(SyncRolePermissions::class)->handle();

        expect(RoleModel::count())->toBe(count(Role::cases()))
            ->and(PermissionModel::count())->toBe(count(P::cases()))
            ->and($result['created_permissions'])->toHaveCount(count(P::cases()))
            ->and(RoleModel::findByName('FINANCE')->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect(Role::FINANCE->permissions())->map->value->sort()->values()->all());

        expect(auditOf(AuditAction::ROLE_PERMISSIONS_SYNCED))->toHaveCount(1);
    });

    it('is idempotent and audits only real changes', function () {
        syncRoles();
        $result = app(SyncRolePermissions::class)->handle();

        expect($result)->toBe(['created_permissions' => [], 'removed_permissions' => [], 'changed_roles' => []])
            ->and(auditOf(AuditAction::ROLE_PERMISSIONS_SYNCED))->toHaveCount(1);
    });

    it('repairs drift and removes permissions that left the catalogue', function () {
        syncRoles();
        $legacy = PermissionModel::create(['name' => 'legacy.backdoor', 'guard_name' => 'web']);
        RoleModel::findByName('AUDITOR')->givePermissionTo($legacy);
        RoleModel::findByName('FINANCE')->revokePermissionTo('settlements.verify');

        $result = app(SyncRolePermissions::class)->handle();

        expect($result['removed_permissions'])->toBe(['legacy.backdoor'])
            ->and($result['changed_roles']['FINANCE']['added'])->toBe(['settlements.verify'])
            ->and(PermissionModel::query()->where('name', 'legacy.backdoor')->exists())->toBeFalse()
            ->and(RoleModel::findByName('FINANCE')->hasPermissionTo('settlements.verify'))->toBeTrue();

        expect(auditOf(AuditAction::ROLE_PERMISSIONS_SYNCED)->last()->metadata['removed_permissions'])->toBe(['legacy.backdoor']);
    });

    it('is exposed as an artisan command', function () {
        $this->artisan('identity:sync-roles')->expectsOutputToContain('synchronised')->assertSuccessful();
        $this->artisan('identity:sync-roles')->expectsOutputToContain('already up to date')->assertSuccessful();
    });
});

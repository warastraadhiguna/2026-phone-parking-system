<?php

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Actions\SyncRolePermissions;
use Illuminate\Console\Command;

/** Applies the code-defined role → permission matrix to the database. Run on every deploy. */
final class SyncRolesCommand extends Command
{
    protected $signature = 'identity:sync-roles';

    protected $description = 'Sync roles and permissions with the matrix defined in App\Domain\Identity\Enums\Role';

    public function handle(SyncRolePermissions $sync): int
    {
        $result = $sync->handle();

        if ($result['created_permissions'] === [] && $result['removed_permissions'] === [] && $result['changed_roles'] === []) {
            $this->info('Roles and permissions already up to date.');

            return self::SUCCESS;
        }

        foreach ($result['created_permissions'] as $name) {
            $this->line("  + permission {$name}");
        }
        foreach ($result['removed_permissions'] as $name) {
            $this->line("  - permission {$name}");
        }
        foreach ($result['changed_roles'] as $role => $diff) {
            $this->line("  ~ role {$role}: +".count($diff['added']).' / -'.count($diff['removed']));
        }
        $this->info('Roles and permissions synchronised (audited as ROLE_PERMISSIONS_SYNCED).');

        return self::SUCCESS;
    }
}

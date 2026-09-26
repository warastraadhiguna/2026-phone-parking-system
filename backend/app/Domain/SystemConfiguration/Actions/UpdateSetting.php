<?php

namespace App\Domain\SystemConfiguration\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Models\SystemSetting;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

final class UpdateSetting
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(SettingKey $key, int $value, User $actor): void
    {
        [$min, $max] = $key->bounds();
        if ($value < $min || $value > $max) {
            throw new RuleViolation('value', "Nilai harus antara {$min} dan {$max}.", ErrorCode::VALIDATION_FAILED);
        }

        DB::transaction(function () use ($key, $value, $actor) {
            $from = $this->settings->int($key);
            if ($from === $value) {
                return;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $key->value],
                ['value' => $value, 'updated_by' => $actor->id, 'updated_at' => now()],
            );

            $this->audit->handle(AuditAction::SETTING_CHANGED, $actor, 'system_setting', $key->value, [
                'from' => $from,
                'to' => $value,
            ]);
        });

        $this->settings->forget();
    }
}

<?php

namespace App\Http\Admin\SystemConfiguration;

use App\Domain\Identity\Models\User;
use App\Domain\SystemConfiguration\Actions\UpdateSetting;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Models\SystemSetting;
use App\Domain\SystemConfiguration\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function index(Settings $settings): Response
    {
        $stored = SystemSetting::query()->get()->keyBy('key');

        return Inertia::render('Settings/Index', [
            'settings' => array_map(function (SettingKey $key) use ($settings, $stored) {
                [$min, $max] = $key->bounds();
                /** @var SystemSetting|null $row */
                $row = $stored->get($key->value);

                return [
                    'key' => $key->value,
                    'label' => $key->label(),
                    'description' => $key->description(),
                    'value' => $settings->int($key),
                    'default' => $key->default(),
                    'min' => $min,
                    'max' => $max,
                    'updated_at' => $row?->updated_at->toIso8601String(),
                ];
            }, SettingKey::cases()),
        ]);
    }

    public function update(Request $request, string $key, UpdateSetting $update): RedirectResponse
    {
        $settingKey = SettingKey::tryFrom($key) ?? abort(404);
        $data = $request->validate(['value' => ['required', 'integer']], attributes: ['value' => 'nilai']);

        /** @var User $actor */
        $actor = $request->user();
        $update->handle($settingKey, (int) $data['value'], $actor);

        return back()->with('success', "{$settingKey->label()} disimpan.");
    }
}

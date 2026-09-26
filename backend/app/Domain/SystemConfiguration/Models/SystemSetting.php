<?php

namespace App\Domain\SystemConfiguration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An overridden setting value. Written only by UpdateSetting.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property CarbonImmutable $updated_at
 */
class SystemSetting extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

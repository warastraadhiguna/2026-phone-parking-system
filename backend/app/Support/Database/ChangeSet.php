<?php

namespace App\Support\Database;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The pending changes of a model as ['field' => ['from' => …, 'to' => …]], for audit metadata.
 * Call after fill() and before save().
 */
final class ChangeSet
{
    /**
     * @param  list<string>  $ignore
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function of(Model $model, array $ignore = ['updated_at']): array
    {
        $changes = [];

        foreach (array_keys($model->getDirty()) as $field) {
            if (in_array($field, $ignore, true)) {
                continue;
            }

            $changes[$field] = [
                'from' => self::scalar($model->getOriginal($field)),
                'to' => self::scalar($model->getAttribute($field)),
            ];
        }

        return $changes;
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };
    }
}

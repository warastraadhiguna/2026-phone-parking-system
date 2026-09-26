<?php

namespace App\Domain\Reconciliation\Enums;

enum Severity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';

    public function label(): string
    {
        return match ($this) {
            self::ERROR => 'Kesalahan integritas',
            self::WARNING => 'Perlu tindakan',
        };
    }
}

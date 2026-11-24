<?php

namespace App\Support\Enums;

enum Severity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::High => 1, self::Medium => 2, self::Low => 3, self::Info => 4,
        };
    }
}

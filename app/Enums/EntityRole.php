<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EntityRole: string implements HasLabel
{
    case Treasurer = 'treasurer';
    case Capturer = 'capturer';
    case Reviewer = 'reviewer';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Treasurer => 'Treasurer (full access)',
            self::Capturer => 'Bookkeeper (can record entries)',
            self::Reviewer => 'Reviewer / Examiner (view only)',
            self::Viewer => 'Viewer (view only)',
        };
    }

    /** Abilities: capture, setup, reverse, close */
    public function can(string $ability): bool
    {
        return match ($ability) {
            'capture' => in_array($this, [self::Treasurer, self::Capturer], true),
            'setup', 'reverse', 'close' => $this === self::Treasurer,
            default => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Icons\Heroicon;

/**
 * The stored state of one attribute in one locale.
 */
enum FieldStatus: string implements HasColor, HasIcon
{
    /** A value is stored for the locale. */
    case Translated = 'translated';

    /** No value is stored, but readers of the content locale context see a fallback. */
    case Fallback = 'fallback';

    /** No value is stored and nothing falls back. */
    case Missing = 'missing';

    public function getColor(): string
    {
        return match ($this) {
            self::Translated => 'success',
            self::Fallback => 'warning',
            self::Missing => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Translated => Heroicon::CheckCircle,
            self::Fallback => Heroicon::ArrowUturnLeft,
            self::Missing => Heroicon::MinusCircle,
        };
    }
}

<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * How much of a locale is translated, measured against the source.
 */
enum TranslationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Complete = 'complete';

    case Incomplete = 'incomplete';

    case Missing = 'missing';

    /** The source has no text to translate. */
    case NotApplicable = 'not_applicable';

    public function getLabel(): string
    {
        return __("tipi-filament-translations::editor.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Complete => 'success',
            self::Incomplete => 'warning',
            self::Missing, self::NotApplicable => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Complete => Heroicon::CheckCircle,
            self::Incomplete => Heroicon::EllipsisHorizontalCircle,
            self::Missing => Heroicon::MinusCircle,
            self::NotApplicable => Heroicon::NoSymbol,
        };
    }
}

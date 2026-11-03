<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The completeness of one locale of a record, counting only the attributes
 * the translation form edits. Whether the locale is outdated is reported
 * separately: an outdated translation can be complete.
 */
enum TranslationStatus: string implements HasColor, HasIcon, HasLabel
{
    /** Every attribute has a stored value. */
    case Complete = 'complete';

    /** Some attributes have a stored value. */
    case Partial = 'partial';

    /** No attribute has a stored value. */
    case Missing = 'missing';

    public function getLabel(): string
    {
        return __("tipi-filament-translations::editor.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Complete => 'success',
            self::Partial => 'warning',
            self::Missing => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Complete => Heroicon::CheckCircle,
            self::Partial => Heroicon::EllipsisHorizontalCircle,
            self::Missing => Heroicon::MinusCircle,
        };
    }
}

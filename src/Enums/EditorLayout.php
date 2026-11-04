<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

/**
 * How the translation editor arranges fields.
 */
enum EditorLayout: string
{
    /** Each field of the target locale beside the same field of a read-only source locale. */
    case Comparison = 'comparison';

    /** The fields of one locale at a time, without a source. */
    case LocaleFocused = 'locale-focused';
}

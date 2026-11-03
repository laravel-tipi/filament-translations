<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

/**
 * How editors switch between the locales they edit.
 */
enum LocaleNavigation: string
{
    /** One tab per locale: best for a handful of locales. */
    case Tabs = 'tabs';

    /** A select: compact, and suited to many locales. */
    case Select = 'select';
}

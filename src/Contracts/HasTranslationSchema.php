<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Contracts;

use Closure;

interface HasTranslationSchema
{
    /**
     * @return array<string, Closure>
     */
    public static function getTranslationSchema(): array;
}

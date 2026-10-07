<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components\Contracts;

use Filament\Schemas\Components\Component;

interface TranslationField
{
    public function getSourceEntry(string $name): Component;
}

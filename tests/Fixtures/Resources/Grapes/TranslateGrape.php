<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes;

use Tipi\Translations\Filament\Resources\Pages\TranslateRecord;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\GrapeResource;

final class TranslateGrape extends TranslateRecord
{
    protected static string $resource = GrapeResource::class;
}

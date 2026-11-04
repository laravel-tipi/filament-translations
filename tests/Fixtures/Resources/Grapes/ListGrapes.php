<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes;

use Filament\Resources\Pages\ListRecords;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\GrapeResource;

final class ListGrapes extends ListRecords
{
    protected static string $resource = GrapeResource::class;
}

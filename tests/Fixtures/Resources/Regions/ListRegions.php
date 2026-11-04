<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions;

use Filament\Resources\Pages\ListRecords;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\RegionResource;

final class ListRegions extends ListRecords
{
    protected static string $resource = RegionResource::class;
}

<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\User;

/**
 * No translate ability: translating follows "update".
 */
final class RegionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Region $region): bool
    {
        return $user->can_edit;
    }
}

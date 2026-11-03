<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Tipi\Translations\Filament\Tests\Fixtures\Models\User;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

/**
 * Translators may be limited to their languages; English can't be deleted.
 */
final class WinePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Wine $wine): bool
    {
        return true;
    }

    public function update(User $user, Wine $wine): bool
    {
        return $user->can_edit;
    }

    public function translate(User $user, Wine $wine, string $locale): bool
    {
        return $user->translator_locales === null ? $user->can_edit : in_array($locale, $user->translator_locales, true);
    }

    public function deleteTranslation(User $user, Wine $wine, string $locale): bool
    {
        return $locale !== 'en';
    }
}

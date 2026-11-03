<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property list<string>|null $translator_locales locales the user may translate; null: all
 * @property bool $can_edit
 */
class User extends Authenticatable implements FilamentUser
{
    protected $guarded = [];

    protected $table = 'users';

    protected function casts(): array
    {
        return [
            'translator_locales' => 'array',
            'can_edit' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}

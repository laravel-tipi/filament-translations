<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\User;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

/**
 * The test environment as a runnable app: vendor/bin/testbench serve.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        $config->set('auth.providers.users.model', User::class);
        $config->set('localization.locales_driver', 'database');
        $config->set('localization.contexts_driver', 'config');
        $config->set('localization.default_context', 'web');
        $config->set('localization.contexts', [
            'web' => ['locales' => '*'],
            'admin' => ['locales' => ['en', 'ka'], 'default' => 'en'],
        ]);
    }

    public function boot(): void
    {
        Gate::policy(Wine::class, WinePolicy::class);
        Gate::policy(Region::class, RegionPolicy::class);

        PublicSite::routes();
    }
}

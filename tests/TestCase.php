<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Tipi\Localization\Actions\CreateLocale;
use Tipi\Localization\Data\CreateLocaleData;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Localization\LocalizationServiceProvider;
use Tipi\Support\Enums\TextDirection;
use Tipi\Translations\Filament\FilamentTranslationsServiceProvider;
use Tipi\Translations\Filament\Tests\Fixtures\AdminPanelProvider;
use Tipi\Translations\Filament\Tests\Fixtures\EditorSettings;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\User;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\PartnersPanelProvider;
use Tipi\Translations\Filament\Tests\Fixtures\PublicSite;
use Tipi\Translations\Filament\Tests\Fixtures\RegionPolicy;
use Tipi\Translations\Filament\Tests\Fixtures\WinePolicy;
use Tipi\Translations\TranslationsServiceProvider;

/**
 * Database-driven locales, in display order:
 *
 * - en English (global default), ka Georgian, de German, ar Arabic (RTL),
 *   all active; fr French, inactive.
 *
 * Contexts: "web" (default context, every active locale) is where content
 * is published; "admin" (en, ka; default ka) is the admin panel's
 * interface context.
 *
 * Panels: "admin" at /admin (context "admin"), "partners" at /partners.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LocalizationServiceProvider::class,
            TranslationsServiceProvider::class,
            FilamentTranslationsServiceProvider::class,
            AdminPanelProvider::class,
            PartnersPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $config->set('auth.providers.users.model', User::class);
        $config->set('database.default', 'testing');

        // SQLite in memory by default; TRANSLATIONS_TEST_DB=mysql|mariadb|pgsql
        // runs on the servers of the Translations package's compose.yaml.
        $driver = getenv('TRANSLATIONS_TEST_DB') ?: 'sqlite';

        if ($driver !== 'sqlite') {
            if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
                throw new \RuntimeException("Unknown test database [{$driver}].");
            }

            $config->set('database.connections.testing', [
                'driver' => $driver,
                'host' => getenv('TRANSLATIONS_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('TRANSLATIONS_TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('TRANSLATIONS_TEST_DB_DATABASE') ?: 'tipi',
                'username' => getenv('TRANSLATIONS_TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('TRANSLATIONS_TEST_DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'search_path' => 'public',
            ]);
        }

        $config->set('localization.locales_driver', 'database');
        $config->set('localization.contexts_driver', 'config');
        $config->set('localization.default_context', 'web');
        $config->set('localization.contexts', [
            'web' => ['locales' => '*'],
            'admin' => ['locales' => ['en', 'ka'], 'default' => 'ka'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        PublicSite::routes();
    }

    /**
     * Whether this process cleared a server database left by an earlier run.
     */
    private static bool $wiped = false;

    protected function defineDatabaseMigrations(): void
    {
        if (! self::$wiped && $this->app['config']->get('database.connections.testing.driver') !== 'sqlite') {
            Schema::dropAllTables();
            self::$wiped = true;
        }

        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel-tipi/localization/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel-tipi/translations/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        EditorSettings::reset();

        $create = $this->app->make(CreateLocale::class);

        $create->execute(new CreateLocaleData(code: 'en', name: 'English', nativeName: 'English', nativeAbbreviation: 'EN', countryCode: 'GB'));
        $create->execute(new CreateLocaleData(code: 'ka', name: 'Georgian', nativeName: 'ქართული', countryCode: 'GE', active: true));
        $create->execute(new CreateLocaleData(code: 'de', name: 'German', nativeName: 'Deutsch', countryCode: 'DE', active: true));
        $create->execute(new CreateLocaleData(code: 'ar', name: 'Arabic', nativeName: 'العربية', textDirection: TextDirection::Rtl, active: true));
        $create->execute(new CreateLocaleData(code: 'fr', name: 'French', nativeName: 'Français', countryCode: 'FR'));

        $this->app->make(LocaleRegistry::class)->flush();
        $this->app->make(LocaleContexts::class)->flush();

        Gate::policy(Wine::class, WinePolicy::class);
        Gate::policy(Region::class, RegionPolicy::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function signIn(array $attributes = []): User
    {
        $user = User::query()->create([
            'name' => 'Editor',
            'email' => uniqid('editor', true).'@example.com',
            'password' => 'secret',
            ...$attributes,
        ]);

        $user->refresh();
        $this->actingAs($user);

        return $user;
    }
}

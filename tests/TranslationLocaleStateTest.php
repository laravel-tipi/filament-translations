<?php

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;
use StijnVanouplines\BladeCountryFlags\BladeCountryFlagsServiceProvider;
use Tipi\Support\Enums\TextDirection;
use Tipi\Support\Locale;
use Tipi\Translations\Concerns\HasLocalizedTranslations;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\FilamentTranslationsServiceProvider;
use Tipi\Translations\Filament\Schemas\Components\TranslationContainer;
use Tipi\Translations\Filament\Schemas\Components\TranslationTextInput;
use Tipi\Translations\TranslationServiceProvider;

final class LocaleStateRecord extends Model implements JsonTranslatableModel
{
    use HasLocalizedTranslations;

    protected $guarded = [];

    protected $casts = ['name' => 'array'];

    public static function getTranslatableAttributes(): array
    {
        return ['name'];
    }
}

final class LocaleStateModal extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function translateAction(): TranslateAction
    {
        return TranslateAction::make()
            ->translatable(fn () => LocaleStateRecord::firstOrFail())
            ->targetLocaleCode(fn (array $arguments): ?string => $arguments['locale'] ?? null)
            ->translationSchema(['name' => fn () => TranslationTextInput::make('name')]);
    }

    public function container(): TranslationContainer
    {
        return $this->getSchema('mountedActionSchema0')->getComponents()[0];
    }

    public function render(): string
    {
        return '<div>{{ $this->translateAction }}<x-filament-actions::modals /></div>';
    }
}

abstract class TranslationLocaleStateTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCountryFlagsServiceProvider::class,
            TranslationServiceProvider::class,
            FilamentTranslationsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('locale_state_records', function (Blueprint $table): void {
            $table->id();
            $table->json('name');
            $table->timestamps();
        });
        LocaleStateRecord::create(['name' => ['en' => 'English source', 'ka' => 'Georgian source']]);

        $locales = collect([
            'en' => new Locale('en', 'English', 'English', 'GB', TextDirection::Ltr, true, true),
            'ka' => new Locale('ka', 'Georgian', 'Georgian', 'GE', TextDirection::Ltr, true, false),
            'de' => new Locale('de', 'German', 'German', 'DE', TextDirection::Ltr, true, false),
            'fr' => new Locale('fr', 'French', 'French', 'FR', TextDirection::Ltr, true, false),
        ]);
        $provider = Mockery::mock(LocaleProvider::class);
        $provider->shouldReceive('supported')->andReturn($locales);
        $provider->shouldReceive('current', 'default')->andReturn($locales['en']);
        $provider->shouldReceive('supportedLocale')->andReturnUsing(fn (string $code) => $locales[$code]);
        $this->app->instance(LocaleProvider::class, $provider);
        Livewire::component('locale-state-modal', LocaleStateModal::class);
    }
}

uses(TranslationLocaleStateTestCase::class);

function assertLocaleState($modal, ?string $target, array $sources): void
{
    $container = $modal->instance()->container();
    expect(array_keys($container->getSourceLocaleSchema()->getComponents()[0]->getOptions()))->toBe($sources);
    expect($container->getTargetHeaderSchema()->getComponents()[0]->getState())
        ->toBe(match ($target) {
            'de' => 'German', 'fr' => 'French', 'ka' => 'Georgian', 'en' => 'English', default => 'Translation'
        });
}

it('keeps the selected target heading through source changes and Livewire renders', function (): void {
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate');
    assertLocaleState($modal, null, ['en', 'ka']);
    $modal->set('mountedActions.0.data.target_locale_code', 'de');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->set('mountedActions.0.data.source_locale_code', 'ka')
        ->assertSet('mountedActions.0.data.source_translation.name', 'Georgian source')
        ->assertSet('mountedActions.0.data.target_locale_code', 'de');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->call('$refresh');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->set('mountedActions.0.data.source_locale_code', 'en');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->set('mountedActions.0.data.target_locale_code', 'fr');
    assertLocaleState($modal, 'fr', ['en', 'ka']);
});

it('excludes a created target while the parent modal stays mounted', function (): void {
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate')
        ->set('mountedActions.0.data.target_locale_code', 'de')
        ->set('mountedActions.0.data.target_translation.name', 'German target');
    $footer = $modal->instance()->container()->getTargetFooterSchema()->getComponents()[0];
    $modal->call('mountAction', 'createTargetTranslation', [], ['schemaComponent' => $footer->getSchemaContainer()->getKey()]);
    $modal->assertHasNoErrors()->assertSet('mountedActions.0.name', 'translate')
        ->assertSet('mountedActions.0.data.target_locale_code', 'de');
    expect(LocaleStateRecord::first()->getTranslation('de')->attributes)->toBe(['name' => 'German target']);
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->call('$refresh');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $target = $modal->instance()->container()->getTargetLocaleSchema()->getComponents()[0];
    expect($target->getOptions())->toHaveKeys(['de', 'fr'])
        ->and($target->isDisabled())->toBeFalse();
    $modal->set('mountedActions.0.data.source_locale_code', 'ka');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->set('mountedActions.0.data.target_locale_code', 'fr');
    assertLocaleState($modal, 'fr', ['en', 'ka', 'de']);
    $modal->set('mountedActions.0.data.source_locale_code', 'de')
        ->assertSet('mountedActions.0.data.source_translation.name', 'German target');
    assertLocaleState($modal, 'fr', ['en', 'ka', 'de']);
});

it('replaces only an invalid source when the target changes', function (): void {
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate')
        ->set('mountedActions.0.data.target_translation.name', 'Draft')
        ->set('mountedActions.0.data.source_locale_code', 'ka')
        ->set('mountedActions.0.data.target_locale_code', 'ka')
        ->assertSet('mountedActions.0.data.source_locale_code', 'en')
        ->assertSet('mountedActions.0.data.source_translation.name', 'English source')
        ->assertSet('mountedActions.0.data.target_translation.name', 'Draft');
    assertLocaleState($modal, 'ka', ['en']);
    $modal->set('mountedActions.0.data.target_locale_code', 'de')
        ->assertSet('mountedActions.0.data.source_locale_code', 'en');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    $modal->call('$refresh');
    assertLocaleState($modal, 'de', ['en', 'ka']);
});

it('clears an invalid source when no alternative translation exists', function (): void {
    LocaleStateRecord::first()->update(['name' => ['en' => 'English source']]);
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate')
        ->set('mountedActions.0.data.target_translation.name', 'Draft')
        ->set('mountedActions.0.data.target_locale_code', 'en')
        ->assertSet('mountedActions.0.data.source_locale_code', null)
        ->assertSet('mountedActions.0.data.source_translation', [])
        ->assertSet('mountedActions.0.data.target_translation.name', 'Draft');
    assertLocaleState($modal, 'en', []);
    $modal->call('$refresh');
    expect($modal->instance()->container()->isSourceVisible())->toBeFalse();
    assertLocaleState($modal, 'en', []);
});

it('fills fresh independent locale state when reopening the modal', function (): void {
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate')
        ->set('mountedActions.0.data.target_locale_code', 'de')
        ->set('mountedActions.0.data.source_locale_code', 'ka')
        ->call('unmountAction')
        ->call('mountAction', 'translate')
        ->assertSet('mountedActions.0.data.source_locale_code', 'en')
        ->assertSet('mountedActions.0.data.target_locale_code', null);
    assertLocaleState($modal, null, ['en', 'ka']);
});

it('preserves configured target locales across source updates', function (): void {
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate', ['locale' => 'de'])
        ->assertSet('mountedActions.0.data.target_locale_code', 'de')
        ->set('mountedActions.0.data.source_locale_code', 'ka');
    assertLocaleState($modal, 'de', ['en', 'ka']);
    expect($modal->instance()->container()->getTargetLocaleSchema()->getComponents()[0]->isDisabled())->toBeTrue();
    $modal->call('$refresh');
    assertLocaleState($modal, 'de', ['en', 'ka']);
});

it('prefills a single missing target but respects an explicitly cleared selection', function (): void {
    LocaleStateRecord::first()->update(['name' => ['en' => 'English source', 'ka' => 'Georgian source', 'de' => 'German source']]);
    $modal = Livewire::test(LocaleStateModal::class)->call('mountAction', 'translate')
        ->assertSet('mountedActions.0.data.target_locale_code', 'fr');
    assertLocaleState($modal, 'fr', ['en', 'ka', 'de']);
    expect($modal->instance()->container()->getTargetLocaleSchema()->getComponents()[0]->isDisabled())->toBeTrue();
    $modal->set('mountedActions.0.data.target_locale_code', null)->call('$refresh');
    assertLocaleState($modal, null, ['en', 'ka', 'de']);
});

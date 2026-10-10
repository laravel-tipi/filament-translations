<?php

declare(strict_types=1);

use Filament\Support\ArrayRecord;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Tipi\Support\Enums\TextDirection;
use Tipi\Support\Locale;
use Tipi\Translations\Concerns\HasLocalizedTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Actions\MarkOthersAsOutdatedAction;
use Tipi\Translations\Filament\Components\TranslationsManager;
use Tipi\Translations\Filament\Contracts\HasTranslationSchema;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\TranslationServiceProvider;

class StateTestModel extends Model implements TranslatableModel
{
    use HasLocalizedTranslations;

    public static function getTranslatableAttributes(): array
    {
        return ['name'];
    }
}

final class StatefulTestModel extends StateTestModel implements HasTranslationStates
{
    use InteractsWithTranslationStates;
}

final class StateTestResource implements HasTranslationSchema
{
    public static function getTranslationSchema(): array
    {
        return [];
    }
}

abstract class TranslationStatesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TranslationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel-tipi/translations/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $locale = new Locale('en', 'English', 'English', 'GB', TextDirection::Ltr, true, true);
        $locales = Mockery::mock(LocaleProvider::class);
        $locales->shouldReceive('current')->andReturn($locale);
        $this->app->instance(LocaleProvider::class, $locales);

    }

    protected function translationTable(Model $record): Table
    {
        $manager = new TranslationsManager;
        $manager->mount($record, StateTestResource::class);

        return $manager->table(Table::make($manager));
    }
}

uses(TranslationStatesTestCase::class);

it('registers status and freshness columns for models with translation states', function (): void {
    $columns = $this->translationTable(new StatefulTestModel)->getColumns();

    expect(array_keys($columns))->toBe(['locale_name', 'state.status', 'state.outdated_at'])
        ->and(array_map(fn ($column) => $column->getLabel(), $columns))
        ->toBe(['locale_name' => 'Name', 'state.status' => 'Status', 'state.outdated_at' => 'Freshness']);
});

it('registers only the name column for models without translation states', function (): void {
    expect(array_keys($this->translationTable(new StateTestModel)->getColumns()))
        ->toBe(['locale_name']);
});

it('renders fresh translations as up to date', function (): void {
    $column = $this->translationTable(new StatefulTestModel)->getColumn('state.outdated_at');
    $column->record([ArrayRecord::getKeyName() => 'ka', 'state' => new TranslationState(['outdated_at' => null])]);

    expect($column->toHtml())->toContain('Up to date')->not->toContain('Outdated');
});

it('renders outdated translations as outdated', function (): void {
    $column = $this->translationTable(new StatefulTestModel)->getColumn('state.outdated_at');
    $column->record([ArrayRecord::getKeyName() => 'ka', 'state' => new TranslationState(['outdated_at' => now()])]);

    expect($column->toHtml())->toContain('Outdated')->not->toContain('Up to date');
});

it('hides the mark others outdated action for unsupported records', function (): void {
    $action = MarkOthersAsOutdatedAction::make()->record(new StateTestModel);

    expect($action->getTranslatableRecord())->toBeNull()
        ->and($action->isVisible())->toBeFalse();
});

it('defaults the mark others outdated action to the current locale', function (): void {
    expect(MarkOthersAsOutdatedAction::make()->getLocaleCode())->toBe('en');
});

it('allows overriding the current locale', function (string|Closure $localeCode): void {
    expect(MarkOthersAsOutdatedAction::make()->localeCode($localeCode)->getLocaleCode())->toBe('ka');
})->with([
    'string' => ['ka'],
    'closure' => [fn (): string => 'ka'],
]);

it('marks every other locale outdated while leaving the selected locale current', function (?string $localeCode): void {
    $record = new StatefulTestModel;
    $record->setAttribute('id', 1);

    foreach (['en', 'ka', 'de'] as $code) {
        $record->translationStates()->create(['locale_code' => $code]);
    }

    $otherRecord = new StatefulTestModel;
    $otherRecord->setAttribute('id', 2);
    $otherRecord->translationStates()->create(['locale_code' => 'de']);

    $action = MarkOthersAsOutdatedAction::make()
        ->translatable($record)
        ->localeCode($localeCode);

    expect($action->isVisible())->toBeTrue();

    $action->call();

    $states = $record->translationStates()->get()->keyBy('locale_code');
    $selectedLocale = $localeCode ?? 'en';

    foreach ($states as $code => $state) {
        if ($code === $selectedLocale) {
            expect($state->outdated_at)->toBeNull();
        } else {
            expect($state->outdated_at)->not->toBeNull();
        }
    }

    expect($otherRecord->translationStates()->first()->outdated_at)->toBeNull();
})->with(['current locale' => [null], 'overridden locale' => ['ka']]);

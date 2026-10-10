<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Orchestra\Testbench\TestCase;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Components\TranslationsManager;
use Tipi\Translations\Filament\Contracts\HasTranslationSchema;
use Tipi\Translations\Filament\FilamentTranslationsServiceProvider;
use Tipi\Translations\Filament\Schemas\Components\TranslationContainer;
use Tipi\Translations\Filament\Schemas\Components\TranslationGroup;
use Tipi\Translations\Filament\Schemas\Components\TranslationTextInput;

final class RulesResource extends Resource implements HasTranslationSchema
{
    public static function getTranslationSchema(): array
    {
        return ['name' => fn () => TranslationTextInput::make('name')->maxLength(8)->rules(['not_in:blocked'])];
    }

    public static function getTranslationRules(): array
    {
        return ['name' => ['required', 'string', 'max:10'], 'description' => ['nullable', 'string']];
    }
}

final class RulesPage extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];

    public static function getResource(): string
    {
        return RulesResource::class;
    }
}

final class RulesManager extends TranslationsManager
{
    public array $data = [];
}

abstract class TranslationRulesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FilamentTranslationsServiceProvider::class];
    }
}

uses(TranslationRulesTestCase::class);

it('applies Resource rules to regular translation fields and groups', function (bool $group, mixed $name, bool $valid): void {
    $page = new RulesPage;
    $page->data = ['translation' => ['name' => $name, 'description' => null]];
    $components = $group
        ? [TranslationGroup::make([TextInput::make('name')->maxLength(8)->rules(['not_in:blocked']), TextInput::make('description')])]
        : [RulesResource::getTranslationSchema()['name'](), TranslationTextInput::make('description')];
    $schema = Schema::make($page)->statePath('data')->components($components);

    expect($schema->getValidationRules()['data.translation.name'])->toContain('required', 'string', 'max:10', 'max:8', 'not_in:blocked');
    if ($valid) {
        expect($schema->getState())->toBe($page->data);
    } else {
        try {
            $schema->getState();
            test()->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('data.translation.name');
        }
    }
})->with([false, true])->with([
    'valid' => ['Hello', true],
    'required' => [null, false],
    'string' => [123, false],
    'Resource maximum' => ['12345678901', false],
    'field maximum' => ['123456789', false],
    'field rules' => ['blocked', false],
]);

trait RulesActionFixture
{
    public array $saved = [];

    public function container(): TranslationContainer
    {
        return $this->getTranslationContainer();
    }

    public function hasSourceTranslation(): bool
    {
        return true;
    }

    protected function getSourceLocaleSelect(): Select
    {
        return Select::make('source_locale_code')->options(['en' => 'English']);
    }

    protected function getTargetLocaleSelect(): Select
    {
        return Select::make('target_locale_code')->options(['ka' => 'Georgian']);
    }
}

final class RulesTranslateAction extends TranslateAction
{
    use RulesActionFixture;

    public function getTargetLocaleCode(): ?string
    {
        return 'ka';
    }

    protected function createTargetTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }

    protected function updateSourceTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }
}

final class RulesEditAction extends EditTranslationAction
{
    use RulesActionFixture;

    protected function updateTranslation(string $localeCode, array $attributes): void
    {
        $this->saved[$localeCode] = $attributes;
    }
}

it('validates Resource rules independently during action saves', function (string $actionClass, string $side, bool $manager, mixed $name, bool $valid): void {
    $livewire = $manager ? new RulesManager : new RulesPage;
    if ($manager) {
        $livewire->resource = RulesResource::class;
    }
    $livewire->data = [
        'source_locale_code' => 'en', 'target_locale_code' => 'ka',
        'source_translation' => ['name' => $side === 'source' ? $name : null],
        'target_translation' => ['name' => $side === 'target' ? $name : null],
    ];
    $action = $actionClass::make()->recordTitle('Test')->translationSchema(RulesResource::getTranslationSchema());
    if ($action instanceof RulesEditAction) {
        $action->localeCode('ka');
    }
    $container = $action->container();
    Schema::make($livewire)->statePath('data')->components([$container])->getComponents();
    $footer = $side === 'source' ? $container->getSourceFooterSchema() : $container->getTargetFooterSchema();
    if ($valid) {
        $footer->getComponents()[0]->call();
        expect($action->saved)->toBe([$side === 'source' ? 'en' : 'ka' => ['name' => $name]]);
        expect(fn () => $side === 'source' ? $container->validateTarget() : $container->validateSource())
            ->toThrow(ValidationException::class);
    } else {
        try {
            $footer->getComponents()[0]->call();
            test()->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey("data.{$side}_translation.name")
                ->and($action->saved)->toBe([]);
        }
    }
})->with([RulesTranslateAction::class, RulesEditAction::class])
    ->with(['source', 'target'])->with([false, true])->with([
        'valid' => ['Hello', true],
        'required' => [null, false],
        'Resource maximum' => ['12345678901', false],
        'field rules' => ['blocked', false],
    ]);

it('preserves field required rules alongside Resource nullable rules', function (): void {
    $page = new RulesPage;
    $page->data = ['translation' => ['description' => null]];
    $schema = Schema::make($page)->statePath('data')->components([
        TranslationTextInput::make('description')->required(),
    ]);

    expect($schema->getValidationRules()['data.translation.description'])->toContain('required', 'nullable', 'string')
        ->and(fn () => $schema->getState())->toThrow(ValidationException::class);
});

it('leaves fields outside translation paths unchanged', function (): void {
    $page = new RulesPage;
    $page->data = ['name' => null, 'translation' => ['unconfigured' => null]];
    $schema = Schema::make($page)->statePath('data')->components([
        TextInput::make('name'), TranslationTextInput::make('unconfigured'),
    ]);

    expect($schema->getValidationRules()['data.name'])->not->toContain('required', 'max:10')
        ->and($schema->getState())->toBe($page->data);
});

it('requires translation eligibility and a matching path for centralized rules', function (string $context, string $kind, bool $nested, mixed $value): void {
    $page = new RulesPage;
    $prefix = $nested ? 'brand.' : '';
    data_set($page->data, "{$prefix}{$context}.name", $value);
    $field = ($kind === 'translation field' ? TranslationTextInput::make('name') : TextInput::make('name'))
        ->statePath("{$context}.name")
        ->rules(['not_in:blocked']);
    $components = $kind === 'translation group'
        ? [TranslationGroup::make([
            Group::make([Group::make([$field])]),
        ])->statePath($context)]
        : [Group::make([Group::make([$field])])];
    if ($kind === 'translation group') {
        $field->statePath('name');
    }
    if ($nested) {
        $components = [Group::make($components)->statePath('brand')];
    }
    $schema = Schema::make($page)->statePath('data')->components($components);
    $path = "data.{$prefix}{$context}.name";
    $rules = $schema->getValidationRules()[$path];

    expect($rules)->toContain('not_in:blocked');
    if ($kind === 'unrelated field') {
        expect($rules)->not->toContain('required', 'string', 'max:10')
            ->and($schema->getState())->toBe($page->data);
    } else {
        expect($rules)->toContain('required', 'string', 'max:10');
        if ($value === null) {
            expect(fn () => $schema->getState())->toThrow(ValidationException::class);
        } else {
            expect($schema->getState())->toBe($page->data);
        }
    }
})->with(['translation', 'source_translation', 'target_translation'])
    ->with(['translation field', 'translation group', 'unrelated field'])
    ->with([false, true])->with([[null], ['Hello']]);

it('does not apply centralized rules to eligible fields with nontranslation paths', function (): void {
    $page = new RulesPage;
    $page->data = ['brand' => ['name' => null], 'name' => null];
    $schema = Schema::make($page)->statePath('data')->components([
        TranslationGroup::make([Group::make([TextInput::make('name')])])->statePath('brand'),
        TranslationTextInput::make('name')->statePath('name'),
    ]);

    foreach ($schema->getValidationRules() as $rules) {
        expect($rules)->not->toContain('required', 'max:10');
    }
    expect($schema->getState())->toBe($page->data);
});

it('preserves TranslationGroup ancestry while validating cloned source and target fields', function (string $side): void {
    $page = new RulesPage;
    $page->data = ['brand' => [
        'source_translation' => ['name' => $side === 'source' ? 'Source' : null],
        'target_translation' => ['name' => $side === 'target' ? 'Target' : null],
    ]];
    $source = TextInput::make('name')->statePath('source_translation.name');
    $target = TextInput::make('name')->statePath('target_translation.name');
    $container = TranslationContainer::make()->content('name', [$source], [$target]);
    $schema = Schema::make($page)->statePath('data')->components([
        TranslationGroup::make([Group::make([$container])])->statePath('brand'),
    ]);
    $schema->getValidationRules();

    expect($side === 'source' ? $container->getSourceState() : $container->getTargetState())
        ->toBe(['name' => ucfirst($side)])
        ->and(fn () => $side === 'source' ? $container->getTargetState() : $container->getSourceState())
        ->toThrow(ValidationException::class);
})->with(['source', 'target']);

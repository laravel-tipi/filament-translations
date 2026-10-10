# Laravel Tipi Filament Translations

Filament integration for `laravel-tipi/translations`.

## Requirements

- PHP 8.5+
- Laravel 13
- Filament 5.10+

## Installation

```bash
composer require laravel-tipi/filament-translations
```

Laravel automatically discovers the package service provider.

## Included features

- `TranslateAction` — creates translations from Filament actions and tables.
- `EditTranslationAction` — edits existing translations with optional source-language context.
- `DeleteTranslationAction` — removes translations from translatable records.
- `TranslationsColumn` — displays translation availability for supported locales in Filament tables.
- `TranslationsManager` — manages translations for a translatable record.
- `HasTranslationSchema` — defines the Filament schema used for translated attributes.

The package integrates with `laravel-tipi/translations` and uses `laravel-tipi/filament-support` for shared Filament support functionality.

## Translation validation

Resources implementing `HasTranslationSchema` must define `getTranslationRules()`
(in addition to `getTranslationSchema()`). Return an empty array to use only
field-level validation.

```php
public static function getTranslationRules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
    ];
}
```

The package appends these rules through Filament's native `rules()` method to
fields implementing `TranslationField` or nested inside `TranslationGroup`,
including deeply nested fields. Eligible fields must also be under `translation.*`, `target_translation.*`, and `source_translation.*`.
This includes ordinary fields inside `TranslationGroup`, translation fields on
Resource forms, and fields used by `TranslateAction` and `EditTranslationAction`
on Resource pages or in `TranslationsManager`. Rule keys are attribute paths
relative to the translation, not Livewire state paths.

Field-level `required()`, `rules()`, and `maxLength()` constraints remain active;
both sets of constraints must pass. Saving a source or target translation only
validates that side. Fields without a matching Resource rule retain their existing
validation. Standalone actions without a Resource context use field-level rules.

## License

MIT.

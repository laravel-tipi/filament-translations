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

## License

MIT.

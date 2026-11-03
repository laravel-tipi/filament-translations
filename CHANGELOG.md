# Changelog

## 0.2.0

A new package, built on `laravel-tipi/translations` 0.2 and `laravel-tipi/localization` 0.2:

- `TranslationEditor`: page, modal or slide-over presentation; comparison or locale-focused layout; tabs or select navigation; content context, locales, inactive locales and source locale. Panel defaults through `FilamentTranslationsPlugin`, resource overrides through the `Translatable` concern, action overrides through `TranslateAction::editor()`.
- `TranslateAction` and the `TranslateRecord` page.
- `TranslationsColumn` and `TranslationStatusFilter`.
- Exact values, one transaction for every changed locale, conflict detection, locale-level authorization, translation state actions.

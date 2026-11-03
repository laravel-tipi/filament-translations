@php
    $progress = $getProgress();
    $orphans = $getOrphans();
    $unsaved = $getUnsavedTexts();
    $fallback = $progress['translated'] === 0 ? $getFallbackLocale() : null;
    $source = $getSourceLocale();
    $locale = $getLocale();
@endphp

<div {{ $attributes->merge($getExtraAttributes(), escape: false)->class(['fi-tipi-tr-attribute']) }} data-attribute="{{ $getAttributeName() }}">
    <div class="fi-tipi-tr-attribute-header">
        <h3 class="fi-tipi-tr-attribute-label">{{ $getLabel() }}</h3>

        @if ($progress['total'] > 1)
            <span class="fi-tipi-tr-muted">
                {{ __('tipi-filament-translations::editor.panel.progress', $progress) }}
            </span>
        @endif

        @if ($fallback !== null && $progress['total'] > 0)
            <span class="fi-tipi-tr-muted">
                · {{ __('tipi-filament-translations::editor.attribute.fallback', ['locale' => $fallback->name]) }}
            </span>
        @endif
    </div>

    @if ($isUnsupported())
        <p class="fi-tipi-tr-notice" role="status">
            {{ __('tipi-filament-translations::editor.attribute.unsupported') }}
        </p>
    @elseif ($progress['total'] === 0)
        <p class="fi-tipi-tr-muted">
            {{ __('tipi-filament-translations::editor.attribute.empty', ['locale' => $source->name]) }}
        </p>
    @endif

    @if ($unsaved !== [])
        <div class="fi-tipi-tr-notice fi-tipi-tr-notice-danger" role="alert">
            <p>{{ __('tipi-filament-translations::editor.attribute.unsaved') }}</p>

            <ul class="fi-tipi-tr-reference-list" lang="{{ $locale->code }}" dir="{{ $locale->textDirection->value }}">
                @foreach ($unsaved as $text)
                    <li>{{ $text }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($orphans !== [])
        <details class="fi-tipi-tr-notice">
            <summary>{{ __('tipi-filament-translations::editor.attribute.orphans', ['count' => count($orphans)]) }}</summary>

            <ul class="fi-tipi-tr-reference-list" lang="{{ $locale->code }}" dir="{{ $locale->textDirection->value }}">
                @foreach ($orphans as $text)
                    <li>{{ $text }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="fi-tipi-tr-rows">
        {{ $getChildSchema() }}
    </div>
</div>

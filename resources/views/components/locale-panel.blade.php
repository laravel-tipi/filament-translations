@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;

    $locale = $getLocale();
    $progress = $getProgress();
    $isActive = $isActive();
    $isComparison = $isComparison();
    $source = $isComparison ? $getSourceLocale() : null;
    $sourceProgress = $source === null ? null : $getSourceProgress();
    $navigation = Js::from($getNavigationStatePath());
    $code = Js::from($locale->code);
@endphp

<section
    {{
        $attributes
            ->merge($getExtraAttributes(), escape: false)
            ->class([
                'fi-tipi-tr-panel',
                'fi-tipi-tr-panel-comparison' => $source !== null,
            ])
    }}
    aria-label="{{ $locale->name }}"
    data-locale="{{ $locale->code }}"
    @unless ($isActive)
        hidden
        style="display: none"
    @endunless
    {{-- Filament dispatches "expand" up from the first invalid field: show its locale. --}}
    x-on:expand="if ($wire.get({{ $navigation }}) !== {{ $code }}) $wire.set({{ $navigation }}, {{ $code }})"
>
    <header class="fi-tipi-tr-panel-header">
        @if ($source !== null)
            <div class="fi-tipi-tr-heading fi-tipi-tr-heading-source">
                <span class="fi-tipi-tr-heading-role">{{ __('tipi-filament-translations::editor.panel.source') }}</span>
                <span class="fi-tipi-tr-heading-locale" lang="{{ $source->code }}">{{ $source->name }}</span>

                @if ($sourceProgress !== null)
                    <x-filament::badge size="sm" :color="$sourceProgress->status()->getColor()" :tooltip="$sourceProgress->summary()">
                        {{ $sourceProgress->fraction() }}
                    </x-filament::badge>
                @endif
            </div>
        @endif

        <div class="fi-tipi-tr-heading fi-tipi-tr-heading-target">
            @if ($source !== null)
                <span class="fi-tipi-tr-heading-role">{{ __('tipi-filament-translations::editor.panel.target') }}</span>
            @endif

            <span class="fi-tipi-tr-heading-locale" lang="{{ $locale->code }}">{{ $locale->name }}</span>

            @if ($locale->nativeName !== $locale->name)
                <span class="fi-tipi-tr-muted" lang="{{ $locale->code }}" dir="{{ $locale->textDirection->value }}">{{ $locale->nativeName }}</span>
            @endif

            <x-filament::badge size="sm" :color="$progress->status()->getColor()" :icon="$progress->status()->getIcon()" :tooltip="$progress->summary()">
                {{ __('tipi-filament-translations::editor.panel.progress', ['translated' => $progress->translated, 'total' => $progress->total]) }}
            </x-filament::badge>

            @if ($progress->isOutdated())
                <x-filament::badge size="sm" color="warning" :icon="Heroicon::Clock">
                    {{ __('tipi-filament-translations::editor.panel.outdated') }}
                </x-filament::badge>
            @endif

            @if ($progress->workflowStatus !== null)
                <x-filament::badge size="sm" color="info">
                    {{ $progress->workflowStatus instanceof \Filament\Support\Contracts\HasLabel ? $progress->workflowStatus->getLabel() : ($progress->workflowStatus instanceof \BackedEnum ? $progress->workflowStatus->value : $progress->workflowStatus) }}
                </x-filament::badge>
            @endif

            @if ($locale->isInactive())
                <x-filament::badge size="sm" color="gray">
                    {{ __('tipi-filament-translations::editor.panel.inactive') }}
                </x-filament::badge>
            @endif

            <div class="fi-tipi-tr-heading-actions">
                {{ $getChildSchema('actions') }}
            </div>
        </div>
    </header>

    @if ($progress->isOutdated())
        <p class="fi-tipi-tr-notice" role="status">
            {{ __('tipi-filament-translations::editor.panel.outdated_notice', ['date' => $progress->outdatedSince?->isoFormat('LL')]) }}
        </p>
    @endif

    <div class="fi-tipi-tr-rows">
        {{ $getChildSchema('rows') }}
    </div>
</section>

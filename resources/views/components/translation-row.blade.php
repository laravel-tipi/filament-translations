@php
    $locale = $getLocale();
    $source = $isComparison() ? $getSourceLocale() : null;
    [$status] = $getStatus();
@endphp

<div
    {{
        $attributes
            ->merge($getExtraAttributes(), escape: false)
            ->class([
                'fi-tipi-tr-row',
                'fi-tipi-tr-row-comparison' => $source !== null,
            ])
    }}
>
    @if ($source !== null)
        <div {{ (new \Illuminate\View\ComponentAttributeBag($getCellLanguage('source')))->class(['fi-tipi-tr-cell', 'fi-tipi-tr-cell-source']) }}>
            <span class="fi-tipi-tr-cell-locale">{{ $source->name }}</span>

            {{ $getChildSchema('source') }}
        </div>
    @endif

    <div {{ (new \Illuminate\View\ComponentAttributeBag($getCellLanguage('target')))->class(['fi-tipi-tr-cell', 'fi-tipi-tr-cell-target']) }}>
        @if ($source !== null)
            <span class="fi-tipi-tr-cell-locale">{{ $locale->name }}</span>
        @endif

        {{ $getChildSchema('target') }}

        <div class="fi-tipi-tr-row-footer">
            <x-filament::badge size="sm" :color="$status->getColor()" :icon="$status->getIcon()">
                {{ $getStatusLabel() }}
            </x-filament::badge>

            @if ($source !== null)
                {{ $getChildSchema('actions') }}
            @endif
        </div>
    </div>
</div>

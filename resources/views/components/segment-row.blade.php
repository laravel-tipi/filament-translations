@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;

    $segment = $getSegment();
    $locale = $getLocale();
    $isComparison = $isComparison();
    $reference = $getReferenceLocale();
    $referenceText = $getReferenceText();
    $conflict = $getConflict();
    $inputPath = Js::from($getInputStatePath());
    $label = $getLabel();
    $showLabel = $segment->context !== [] || $segment->role !== 'text';
@endphp

<div
    {{
        $attributes
            ->merge($getExtraAttributes(), escape: false)
            ->class([
                'fi-tipi-tr-row',
                'fi-tipi-tr-row-comparison' => $isComparison,
                'fi-tipi-tr-row-conflict' => $conflict !== null,
            ])
    }}
    data-segment="{{ $segment->key() }}"
>
    <div class="fi-tipi-tr-cell fi-tipi-tr-cell-reference" lang="{{ $reference->code }}" dir="{{ $reference->textDirection->value }}">
        @if ($showLabel)
            <span class="fi-tipi-tr-row-label" lang="{{ app()->getLocale() }}" dir="ltr">{{ $label }}</span>
        @endif

        @if ($referenceText !== null)
            <div class="fi-tipi-tr-reference">{{ $markup($referenceText) }}</div>
        @else
            <div class="fi-tipi-tr-reference fi-tipi-tr-muted" lang="{{ app()->getLocale() }}" dir="ltr">
                {{ __('tipi-filament-translations::editor.row.no_reference', ['locale' => $reference->name]) }}
            </div>
        @endif
    </div>

    <div class="fi-tipi-tr-cell fi-tipi-tr-cell-target">
        {{ $getChildSchema() }}

        @if ($conflict !== null)
            <div class="fi-tipi-tr-conflict" role="alert">
                @if ($conflict['reason'] === 'changed')
                    <p>{{ __('tipi-filament-translations::editor.row.conflict_changed') }}</p>

                    @if ($conflict['theirs'] !== null)
                        <blockquote lang="{{ $locale->code }}" dir="{{ $locale->textDirection->value }}">{{ $markup($conflict['theirs']) }}</blockquote>
                    @else
                        <p class="fi-tipi-tr-muted">{{ __('tipi-filament-translations::editor.row.conflict_removed') }}</p>
                    @endif

                    <x-filament::link
                        tag="button"
                        type="button"
                        size="sm"
                        :icon="Heroicon::ArrowUturnLeft"
                        x-on:click="$wire.set({{ $inputPath }}, {{ Js::from($conflict['theirs']) }}); $wire.set({{ Js::from($getConflictStatePath()) }}, null)"
                    >
                        {{ __('tipi-filament-translations::editor.row.use_theirs') }}
                    </x-filament::link>
                @else
                    <p>{{ __('tipi-filament-translations::editor.row.conflict_source') }}</p>
                @endif
            </div>
        @endif

        <div class="fi-tipi-tr-row-footer">
            @if ($isTranslated())
                <x-filament::badge size="sm" color="success" :icon="Heroicon::CheckCircle">
                    {{ __('tipi-filament-translations::editor.row.translated') }}
                </x-filament::badge>
            @else
                <x-filament::badge size="sm" color="gray" :icon="Heroicon::MinusCircle">
                    {{ __('tipi-filament-translations::editor.row.missing') }}
                </x-filament::badge>
            @endif

            @if ($referenceText !== null)
                <x-filament::link
                    tag="button"
                    type="button"
                    size="sm"
                    color="gray"
                    :icon="Heroicon::DocumentDuplicate"
                    :tooltip="__('tipi-filament-translations::editor.row.copy_tooltip', ['locale' => $reference->name])"
                    x-on:click="$wire.set({{ $inputPath }}, {{ Js::from($referenceText) }})"
                >
                    {{ __('tipi-filament-translations::editor.row.copy', ['locale' => $reference->name]) }}
                </x-filament::link>
            @endif
        </div>
    </div>
</div>

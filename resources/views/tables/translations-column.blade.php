@php
    use Filament\Support\Icons\Heroicon;

    $items = $getItems();
    $limit = $getLimit();
@endphp

<div {{ $attributes->merge($getExtraAttributes(), escape: false)->class(['fi-tipi-tr-column']) }}>
    @if (count($items) > $limit)
        @php
            $complete = collect($items)->filter(fn (array $item): bool => $item['progress']->status() === \Tipi\Translations\Filament\Enums\TranslationStatus::Complete)->count();
            $outdated = collect($items)->filter(fn (array $item): bool => $item['progress']->isOutdated())->count();
            $link = $getLink($getRecord(), null);
            $tooltip = collect($items)->map(fn (array $item): string => $item['progress']->summary())->implode("\n");
            $color = $outdated > 0 ? 'warning' : ($complete === count($items) ? 'success' : ($complete === 0 ? 'gray' : 'info'));
        @endphp

        <x-filament::badge
            :color="$color"
            :icon="$outdated > 0 ? Heroicon::Clock : null"
            :tooltip="$tooltip"
            :tag="$link['href'] ? 'a' : ($link['click'] ? 'button' : 'span')"
            :href="$link['href']"
            :wire:click="$link['click']"
            :aria-label="__('tipi-filament-translations::editor.column.summary', ['complete' => $complete, 'total' => count($items)])"
        >
            {{ __('tipi-filament-translations::editor.column.summary', ['complete' => $complete, 'total' => count($items)]) }}
        </x-filament::badge>
    @else
        <ul class="fi-tipi-tr-column-list" role="list">
            @foreach ($items as $item)
                @php($progress = $item['progress'])

                <li>
                    <x-filament::badge
                        size="sm"
                        :color="$progress->color()"
                        :icon="$progress->isOutdated() ? Heroicon::Clock : ($progress->hasDrafts() ? Heroicon::PencilSquare : null)"
                        :tooltip="$progress->summary()"
                        :tag="$item['href'] ? 'a' : ($item['click'] ? 'button' : 'span')"
                        :href="$item['href']"
                        :wire:click="$item['click']"
                        :aria-label="$progress->summary()"
                        :class="$progress->locale->isInactive() ? 'fi-tipi-tr-column-inactive' : null"
                    >
                        {{ strtoupper($progress->locale->code) }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    @endif
</div>

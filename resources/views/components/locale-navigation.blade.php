@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;
    use Tipi\Translations\Filament\Enums\LocaleNavigation;

    $statePath = $getStatePath();
    $items = $getItems();
    $id = $getId() ?? 'tipi-translations-locale';
@endphp

<div {{ $attributes->merge($getExtraAttributes(), escape: false)->class(['fi-tipi-tr-nav']) }}>
    @if ($items === [])
        {{-- The editor shows an empty state instead. --}}
    @elseif ($getVariant() === LocaleNavigation::Tabs)
        <x-filament::tabs :label="__('tipi-filament-translations::editor.navigation.label')">
            @foreach ($items as $item)
                @php($locale = $item['locale'])

                <x-filament::tabs.item
                    :active="$item['active']"
                    :badge="$item['errors'] ? __('tipi-filament-translations::editor.navigation.errors') : $item['progress']->fraction()"
                    :badge-color="$item['errors'] ? 'danger' : $item['progress']->color()"
                    :badge-icon="$item['errors'] ? Heroicon::ExclamationCircle : ($item['progress']->isOutdated() ? Heroicon::Clock : null)"
                    :badge-tooltip="$item['progress']->summary()"
                    :aria-current="$item['active'] ? 'true' : null"
                    :title="$item['progress']->summary()"
                    wire:key="tipi-translation-tab-{{ $locale->code }}"
                    wire:click="$set({{ Js::from($statePath) }}, {{ Js::from($locale->code) }})"
                    wire:loading.attr="disabled"
                >
                    <span lang="{{ $locale->code }}">{{ $locale->name }}</span>

                    @if ($locale->isInactive())
                        <span class="fi-tipi-tr-muted">{{ __('tipi-filament-translations::editor.panel.inactive') }}</span>
                    @endif
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @else
        @php($active = collect($items)->firstWhere('active', true))

        <div class="fi-tipi-tr-nav-select">
            <label for="{{ $id }}" class="fi-tipi-tr-nav-select-label">
                {{ __('tipi-filament-translations::editor.navigation.select_label') }}
            </label>

            <x-filament::input.wrapper :valid="! collect($items)->contains('errors', true)">
                <x-filament::input.select :id="$id" wire:model.live="{{ $statePath }}">
                    @foreach ($items as $item)
                        <option value="{{ $item['locale']->code }}" @selected($item['active'])>
                            {{ $item['locale']->name }}
                            · {{ $item['progress']->fraction() }}
                            @if ($item['progress']->isOutdated())
                                · {{ __('tipi-filament-translations::editor.panel.outdated') }}
                            @endif
                            @if ($item['locale']->isInactive())
                                · {{ __('tipi-filament-translations::editor.panel.inactive') }}
                            @endif
                            @if ($item['errors'])
                                · {{ __('tipi-filament-translations::editor.navigation.errors') }}
                            @endif
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif
</div>

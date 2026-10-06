{{-- resources/views/tables/columns/translations-column.blade.php --}}

<ul class="flex items-center gap-3 px-3 py-4">
    @foreach ($column->getLocales() as $locale)
        @php
            $record = $getRecord();

            $exists = $column->translationExists(
                record: $record,
                localeCode: $locale->code,
                );
        @endphp

        @if ($record === null)
            @continue
        @endif

        <li>
            @if ($exists)
                <x-filament::icon-button
                    icon="heroicon-m-pencil-square"
                    :tooltip="'Edit ' . $locale->name . ' translation'"
                    wire:click.stop="mountTableAction(
                        'edit_translation',
                        '{{ $record->getKey() }}',
                        { locale: '{{ $locale->code }}' }
                    )"
                />
            @else
                <x-filament::icon-button
                    icon="heroicon-m-plus"
                    :tooltip="'Add ' . $locale->name . ' translation'"
                    wire:click.stop="mountTableAction(
                        'translate',
                        '{{ $record->getKey() }}',
                        { locale: '{{ $locale->code }}' }
                    )"
                />
            @endif
        </li>
    @endforeach
</ul>

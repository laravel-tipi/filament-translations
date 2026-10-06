{{-- src/resources/views/tables/columns/translations-column-header.blade.php --}}

<ul class="flex items-center gap-3">
    @foreach ($column->getLocales() as $locale)
        <li>
            @svg (
                'flag-4x3-' . $locale->countryCode ?? 'un',
                'h-5 w-5'
            )
        </li>
    @endforeach
</ul>

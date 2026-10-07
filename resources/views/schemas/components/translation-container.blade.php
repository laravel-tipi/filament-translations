{{-- resources/views/schemas/components/translation-container.blade.php --}}

@php
    $hasSource = $isSourceVisible();

    $sourceLocale = $getSourceLocaleSchema();
    $targetLocale = $getTargetLocaleSchema();

    $sourceHeader = $getSourceHeaderSchema();
    $targetHeader = $getTargetHeaderSchema();

    $sourceFooter = $getSourceFooterSchema();
    $targetFooter = $getTargetFooterSchema();
@endphp

<div class="space-y-6 pb-6">
    {{-- Locales --}}
    @if ($sourceLocale || $targetLocale)
        <div
            @class([
                'grid gap-6 border-b border-gray-200 pb-6 dark:border-white/10',
                'grid-cols-2' => $hasSource,
                'grid-cols-1' => ! $hasSource,
            ])
        >
            @if ($hasSource)
                <div class="flex justify-end">
                    <div class="w-full max-w-60">
                        {{ $sourceLocale }}
                    </div>
                </div>
            @endif

            <div
                @class([
                    'flex',
                    'justify-start' => $hasSource,
                    'justify-end' => ! $hasSource,
                ])
            >
                <div class="w-full max-w-60">
                    {{ $targetLocale }}
                </div>
            </div>
        </div>
    @endif

    {{-- Headings --}}
    @if ($sourceHeader || $targetHeader)
        <div
            @class([
                'grid gap-6 px-6',
                'grid-cols-2' => $hasSource,
                'grid-cols-1' => ! $hasSource,
            ])
        >
            @if ($hasSource)
                <div class="pe-6">
                    {{ $sourceHeader }}
                </div>
            @endif

            <div @class(['ps-6' => $hasSource])>
                {{ $targetHeader }}
            </div>
        </div>
    @endif

    {{-- Content --}}
    <div class="space-y-6  px-6">
        @foreach ($getContentRows() as $row)
            <div
                @class([
                    'grid gap-0',
                    'grid-cols-2' => $hasSource,
                    'grid-cols-1' => ! $hasSource,
                ])
            >
                @if ($hasSource)
                    <div class="border-e border-gray-200 pe-6 dark:border-white/10">
                        {{ $getSourceContentSchema($row) }}
                    </div>
                @endif

                <div @class(['ps-6' => $hasSource])>
                    {{ $getTargetContentSchema($row) }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    @if ($sourceFooter || $targetFooter)
        <div
            @class([
                'grid gap-0 border-t border-gray-200 pt-6 dark:border-white/10',
                'grid-cols-2' => $hasSource,
                'grid-cols-1' => ! $hasSource,
            ])
        >
            @if ($hasSource)
                <div class="pe-6">
                    {{ $sourceFooter }}
                </div>
            @endif

            <div @class(['ps-6' => $hasSource])>
                {{ $targetFooter }}
            </div>
        </div>
    @endif
</div>

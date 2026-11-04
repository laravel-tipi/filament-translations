<?php

declare(strict_types=1);

use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 | The table's translate action over real Livewire requests, as a browser
 | sends them. Filament answers a mounted action with its modal as a
 | partial, not a full render, so the modal only opens if Filament's
 | partial rendering is wired up.
 */

it('sends the modal of the translate action when it is mounted', function (): void {
    $this->signIn();
    configureEditor(fn (TranslationEditor $e) => $e->modal()->localeTabs());
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    preg_match_all('/wire:snapshot="([^"]+)"/', $this->get('/admin/wines')->assertOk()->getContent(), $snapshots);
    $snapshot = collect($snapshots[1])->map(fn (string $snapshot): string => html_entity_decode($snapshot))
        ->first(fn (string $snapshot): bool => str_ends_with(json_decode($snapshot, true)['memo']['name'], 'ListWines'));

    $response = $this->withHeader('X-Livewire', 'true')
        ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['method' => 'mountAction', 'params' => ['translate', (object) [], ['recordKey' => (string) $wine->getKey(), 'table' => true]], 'metadata' => (object) []]],
        ]]])
        ->assertOk();

    expect($response->json('components.0.effects.partials.action-modals'))->toBeString()
        ->toContain('Translate saperavi')
        ->toContain('tipi-translation-tab-ka');
});

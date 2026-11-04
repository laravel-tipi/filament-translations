<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Illuminate\Database\Seeder;
use Tipi\Localization\Actions\CreateLocale;
use Tipi\Localization\Data\CreateLocaleData;
use Tipi\Support\Enums\TextDirection;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\User;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

final class WorkbenchSeeder extends Seeder
{
    public function run(CreateLocale $create): void
    {
        $create->execute(new CreateLocaleData(code: 'en', name: 'English', nativeName: 'English', nativeAbbreviation: 'EN', countryCode: 'GB'));
        $create->execute(new CreateLocaleData(code: 'ka', name: 'Georgian', nativeName: 'ქართული', countryCode: 'GE', active: true));
        $create->execute(new CreateLocaleData(code: 'de', name: 'German', nativeName: 'Deutsch', countryCode: 'DE', active: true));
        $create->execute(new CreateLocaleData(code: 'ar', name: 'Arabic', nativeName: 'العربية', textDirection: TextDirection::Rtl, active: true));
        $create->execute(new CreateLocaleData(code: 'fr', name: 'French', nativeName: 'Français', countryCode: 'FR'));

        $user = User::query()->create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'password']);

        $wine = Wine::query()->create(['slug' => 'saperavi']);
        $wine->setTranslations('en', [
            'name' => 'Saperavi',
            'description' => '<h2>A Kakhetian classic</h2>'
                .'<p>A deeply coloured <strong>dry red</strong> from Kakheti, aged in <a href="https://en.wikipedia.org/wiki/Qvevri" target="_blank">qvevri</a>.</p>'
                .'<ul><li><p>Black cherry</p></li><li><p>Plum and <em>spice</em></p></li></ul>'
                .'<p><img src="/images/kakheti.jpg" alt="Vineyards in Kakheti"></p>',
            'tasting' => '## Nose

Ripe **black cherry**, plum and a hint of [oak](https://example.com/oak).

- Decant for an hour
- Serve at 16–18 °C',
            'notes' => [
                ['id' => 'cherry', 'note' => 'Black cherry', 'score' => 9],
                ['id' => 'plum', 'note' => 'Plum', 'score' => 8],
            ],
            'content' => [
                ['type' => 'heading', 'data' => ['id' => 'intro', 'text' => 'From the cellar', 'level' => 'h2']],
                ['type' => 'quote', 'data' => ['id' => 'saying', 'text' => 'Where there is wine, there is life.', 'author' => 'Georgian proverb']],
                ['type' => 'image', 'data' => ['id' => 'photo', 'url' => '/images/cellar.jpg']],
            ],
        ])->setTranslations('ka', ['name' => 'საფერავი'])->save();
        $wine->setTranslations('de', ['name' => 'Saperawi'])->save();
        $wine->markTranslationOutdated('de');

        Wine::query()->create(['slug' => 'rkatsiteli'])->setTranslations('en', ['name' => 'Rkatsiteli', 'description' => '<p>Amber wine.</p>'])->save();

        foreach (['Mtsvane', 'Kisi', 'Tavkveri'] as $name) {
            Grape::query()->create()->setTranslations('en', ['name' => $name, 'description' => "**{$name}** is a Georgian grape.

It grows in Kakheti."])->save();
        }

        Region::query()->create(['owner_id' => $user->id])->setTranslations('en', ['name' => 'Kakheti', 'description' => 'Eastern Georgia.'])->save();
    }
}

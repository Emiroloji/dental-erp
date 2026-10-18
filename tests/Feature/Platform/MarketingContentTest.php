<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * Tanıtım sitesi programın bugünkü kapsamını anlatır: stoğun yanında
 * finans (ön muhasebe), ÜTS bildirimleri, hasta ve implant kartı, gelen
 * e-fatura, raporlar ve asistan. Örnek ekranlar
 * HTML kopyalarıdır; uydurma müşteri, yorum ya da kullanım rakamı yoktur.
 */
class MarketingContentTest extends TestCase
{
    public function test_home_page_introduces_every_module(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['Stok', 'Satın alma', 'e-Fatura', 'Transfer', 'Sayım ve iade', 'ÜTS ve hasta', 'Finans', 'Raporlar ve asistan', 'Yetki ve denetim'] as $module) {
            $response->assertSee($module);
        }

        foreach ([
            '#stok', '#satinalma', '#efatura', '#transfer', '#sayim', '#ilac', '#finans', '#rapor', '#yetki',
        ] as $anchor) {
            $response->assertSee(route('marketing.features').$anchor, false);
        }
    }

    public function test_home_page_shows_the_finance_showcase_and_faq(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Toplam nakit')
            ->assertSee('Kasa')
            ->assertSee('Mutabakat')
            ->assertSee('Sık sorulanlar');
    }

    public function test_features_page_has_a_section_for_every_module(): void
    {
        $response = $this->get('/neler-yapiyor')->assertOk();

        foreach (['hiyerarsi', 'stok', 'satinalma', 'efatura', 'transfer', 'sayim', 'uyari', 'ilac', 'hasta', 'finans', 'rapor', 'barkod', 'yetki', 'guvenlik', 'paket', 'kapsam'] as $id) {
            $response->assertSee('id="'.$id.'"', false);
        }

        $response->assertSee('ön muhasebe')
            ->assertSee('ÜTS')
            ->assertDontSee('Muhasebe programı yerine geçmez');
    }

    /**
     * Faz 6–7 sonrası artık doğru olmayan eski iddialar sitede kalmamalı.
     */
    public function test_outdated_claims_are_gone(): void
    {
        foreach (['/', '/neler-yapiyor'] as $path) {
            $this->get($path)
                ->assertDontSee('yalnızca okur')
                ->assertDontSee('bildirim göndermez')
                ->assertDontSee('e-fatura yok')
                ->assertDontSee('Yok, bilinçli olarak');
        }

        $this->get('/neler-yapiyor')
            ->assertSee('Kullanım bildirimi')
            ->assertSee('İmha')
            ->assertSee('Gelen e-fatura')
            ->assertSee('implant kartı', false);
    }

    public function test_page_description_mentions_finance(): void
    {
        $this->get('/')->assertSee('ön muhasebe', false);
    }

    public function test_pages_carry_no_invented_social_proof(): void
    {
        foreach (['/', '/neler-yapiyor'] as $path) {
            $this->get($path)
                ->assertDontSee('klinik kullanıyor')
                ->assertDontSee('müşterimiz');
        }
    }
}

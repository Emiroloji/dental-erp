<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Support\Guides;
use Tests\TestCase;

/**
 * Aşama 52 — ÜTS ve e-fatura rehberleri (girişsiz, arama motorlarına açık).
 * Her rehber resmî bir kaynağa dayanır ve kaynağını gösterir.
 */
class GuideTest extends TestCase
{
    public function test_index_lists_every_guide(): void
    {
        $response = $this->get('/rehber')->assertOk()->assertSee('Rehberler');

        foreach (Guides::all() as $guide) {
            $response->assertSee($guide['title'])->assertSee(route('guides.show', $guide['slug']), false);
        }

        $this->assertGreaterThanOrEqual(13, count(Guides::all()));
    }

    public function test_each_guide_has_its_own_title_description_and_sources(): void
    {
        foreach (Guides::all() as $guide) {
            $this->get(route('guides.show', $guide['slug']))
                ->assertOk()
                ->assertSee('<title>'.e($guide['title']).' | Dental ERP</title>', false)
                ->assertSee('<meta name="description" content="'.e($guide['description']).'">', false)
                ->assertSee('Kaynaklar')
                ->assertSee('application/ld+json', false);

            $this->assertNotEmpty($guide['sources'], $guide['slug'].' kaynaksız');
            $this->assertLessThanOrEqual(160, mb_strlen($guide['description']), $guide['slug'].' açıklaması uzun');
        }
    }

    public function test_unknown_guide_is_not_found(): void
    {
        $this->get('/rehber/olmayan-rehber')->assertNotFound();
    }

    public function test_logged_in_users_can_still_read_guides(): void
    {
        $guide = Guides::all()[0];

        $this->actingAs(User::factory()->make(['role' => User::ROLE_ADMIN]))
            ->get(route('guides.show', $guide['slug']))
            ->assertOk();
    }

    public function test_sitemap_lists_public_pages_and_guides(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        foreach ([route('marketing.home'), route('marketing.features'), route('marketing.contact'), route('guides.index')] as $url) {
            $response->assertSee('<loc>'.$url.'</loc>', false);
        }

        foreach (Guides::all() as $guide) {
            $response->assertSee('<loc>'.route('guides.show', $guide['slug']).'</loc>', false);
        }
    }

    public function test_robots_allows_crawling_and_points_to_the_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('User-agent: *')
            ->assertSee('Disallow: /dashboard')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));
    }
}

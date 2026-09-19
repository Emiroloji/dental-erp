<?php

namespace Tests\Feature\Access;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aşama 21 — PWA (kullanıcı kararı: ayrı native uygulama yerine telefona
 * yüklenebilen web uygulaması). Doğrulama: uygulama ana ekrana eklenebilir
 * (manifest + ikonlar + service worker), bağlantı yokken açıklayıcı çevrimdışı
 * sayfası görünür, oturum gerektiren sayfalar cihazda saklanmaz, telefonda
 * menü açılır panel olarak çalışır.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_installable(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Dental ERP', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertStringStartsWith('/dashboard', $manifest['start_url']);
        $this->assertSame('tr', $manifest['lang']);

        // Yüklenebilirlik için 192 ve 512 PNG ikon; Android için maskable.
        $icons = collect($manifest['icons']);
        foreach (['192x192', '512x512'] as $size) {
            $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === $size && $icon['type'] === 'image/png'), "{$size} ikon");
        }
        $this->assertTrue($icons->contains('purpose', 'maskable'));

        foreach ($icons as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$width}x{$height}", $icon['src']);
        }

        $this->assertSame('/hizli-islem?kaynak=pwa', $manifest['shortcuts'][0]['url']);
        [$width] = getimagesize(public_path('icons/apple-touch-icon.png'));
        $this->assertSame(180, $width);
    }

    public function test_service_worker_never_caches_signed_in_pages(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        // Sayfalar ağdan gelir, yalnızca çevrimdışıyken statik sayfaya düşülür.
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString('fetch(request).catch(() => caches.match(OFFLINE_URL))', $worker);
        // Yalnızca GET ve aynı köken; Livewire POST istekleri dokunulmadan geçer.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        // Önbelleğe yazma yalnızca hash'li derleme dosyaları ve ikonlar için.
        $this->assertSame(1, substr_count($worker, 'cache.put('));
        $this->assertStringContainsString("url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')", $worker);

        $offline = file_get_contents(public_path('offline.html'));
        $this->assertStringContainsString('İnternet bağlantısı yok', $offline);
        $this->assertStringNotContainsString('@', $offline, 'çevrimdışı sayfası statik; Blade/sunucu verisi içermez');

        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", file_get_contents(resource_path('js/app.js')));
    }

    public function test_every_layout_declares_the_app_and_phone_menu(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('apple-touch-icon', false);

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('<meta name="theme-color" content="#0e1a1c">', false)
            ->assertSee('x-data="{ menuOpen: false }"', false)
            ->assertSee('aria-label="Menüyü aç"', false)
            ->assertSee(route('stock.quick'), false);

        $owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
        $this->actingAs($owner)->get('/platform')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('aria-label="Menüyü aç"', false);
    }
}

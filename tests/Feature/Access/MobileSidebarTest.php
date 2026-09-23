<?php

namespace Tests\Feature\Access;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telefonda yan menünün açılması (Aşama 31).
 *
 * Canlıda çıkan hata: hamburger düğmesine basılınca karartma katmanı
 * görünüyordu ama panel yerinden kımıldamıyordu. Sebebi, panelin kapalı
 * konumunu Tailwind'in `translate` CSS özelliğiyle kurması, Alpine'ın açma
 * bağlamasının ise `transform` yazmasıydı — iki ayrı özellik olduğu için
 * `translate: -100%` yerinde kalıyordu. Kalıp Tailwind v3'ten kalmıştı;
 * v3 `transform: translateX(...)` kullanıyordu.
 *
 * Mevcut PWA testi `x-data="{ menuOpen: false }"` ve düğmenin varlığını
 * doğruluyordu; yani biçimlendirmenin var olduğunu kontrol ediyor, açmanın
 * işe yaradığını kontrol etmiyordu. Hata tam bu boşluktan geçti.
 */
class MobileSidebarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tailwind'in kapalı konumu hangi CSS özelliğiyle kurduğunu derlenmiş
     * CSS'ten okur. Sürüm yükseltmesiyle özellik yine değişirse test bunu
     * yakalar; elle yazılmış bir sabit yakalamazdı.
     */
    private function offscreenCssProperty(): ?string
    {
        $files = glob(public_path('build/assets/app-*.css'));

        if ($files === [] || $files === false) {
            return null;
        }

        $css = (string) file_get_contents($files[0]);

        if (! preg_match('/\.-translate-x-full\{([^}]*)\}/', $css, $matches)) {
            return null;
        }

        // "translate:var(--tw-translate-x) ..." veya "transform:translateX(...)"
        return str_contains($matches[1], 'translate:') ? 'translate' : 'transform';
    }

    private function admin(): User
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        return User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);
    }

    public function test_opening_the_menu_sets_the_same_css_property_that_hides_the_panel(): void
    {
        $html = $this->actingAs($this->admin())->get('/dashboard')->assertOk()->getContent();

        // Panel varsayılan olarak ekran dışında: Alpine yüklenmeden önce de
        // kapalı görünmeli, yoksa sayfa açılışında menü bir an parlar.
        $this->assertStringContainsString('-translate-x-full', $html);

        // Açma bağlaması, paneli gizleyen özelliğin AYNISINI yazmalı.
        // "transform" yazarsa satır içi stil `translate: -100%` kuralını
        // ezmez ve panel kımıldamaz.
        $this->assertMatchesRegularExpression(
            '/x-bind:style="menuOpen \? \x27translate:/',
            $html,
            'Yan menüyü açan bağlama "translate" özelliğini yazmalı; "transform" Tailwind v4 kuralını ezmez.',
        );
        $this->assertStringNotContainsString("'transform: translateX(0)'", $html);
    }

    public function test_the_platform_panel_menu_opens_the_same_way(): void
    {
        $owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);

        $html = $this->actingAs($owner)->get('/platform')->assertOk()->getContent();

        $this->assertStringContainsString('-translate-x-full', $html);
        $this->assertMatchesRegularExpression('/x-bind:style="menuOpen \? \x27translate:/', $html);
        $this->assertStringNotContainsString("'transform: translateX(0)'", $html);
    }

    public function test_the_compiled_css_still_positions_the_panel_with_the_translate_property(): void
    {
        $property = $this->offscreenCssProperty();

        if ($property === null) {
            $this->markTestSkipped('Derlenmiş CSS yok (npm run build çalışmamış); yalnızca Blade tarafı doğrulandı.');
        }

        // Tailwind v4 "translate" özelliğini kullanıyor. Bir yükseltme bunu
        // "transform"a geri döndürürse, Blade'deki bağlama da güncellenmeli.
        $this->assertSame(
            'translate',
            $property,
            'Tailwind kapalı konumu artık başka bir CSS özelliğiyle kuruyor; layout dosyalarındaki x-bind:style buna göre güncellenmeli.',
        );
    }
}

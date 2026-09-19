<?php

namespace Tests\Feature\Assistant;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Assistant\Models\AssistantQuery;
use App\Domain\Assistant\Support\AssistantQueryStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 25 — doğal dille rapor sorgulama. Doğrulama: soru yapay zekâ
 * tarafından yalnızca rapor sorgusuna çevrilir; rakamlar sistemin kendi
 * raporlarından ve kullanıcının kapsamında gelir; sağlayıcıya stok verisi
 * gitmez; organizasyon başına günlük/aylık limit uygulanır.
 */
class NaturalQueryTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    private Organization $organization;

    private Branch $central;

    private Branch $north;

    private Category $category;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-18 12:00:00');
        config(['services.gemini.key' => 'test-gemini-key', 'assistant.daily_limit' => 50, 'assistant.monthly_limit' => 1000]);
        RateLimiter::clear('assistant:*');

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $northWarehouse = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->category = Category::create(['name' => 'Kompozitler', 'status' => 'active']);
        Supplier::create(['name' => 'Dental Tedarik', 'status' => 'active']);
        $composite = Product::create(['name' => 'Gizli Kompozit Z', 'code' => 'GZ-9', 'base_unit' => 'Adet', 'category_id' => $this->category->id, 'status' => 'active']);
        $gloves = Product::create(['name' => 'Nitril Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);

        $stock = app(StockMovementService::class);
        $stock->in($composite, $centralWarehouse, 50, ['lot_no' => 'LOT-SIR-1', 'unit_cost' => 40], $this->admin);
        $stock->out($composite, $centralWarehouse, 5, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->in($gloves, $northWarehouse, 80, ['lot_no' => 'LOT-SIR-2', 'unit_cost' => 2], $this->admin);
        $stock->out($gloves, $northWarehouse, 12, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);
    }

    /**
     * Her soruya sırayla bir cevap; son cevap tekrarlanır.
     */
    private function fakeGemini(array ...$intents): void
    {
        $sequence = Http::fakeSequence(self::GEMINI_URL);

        foreach ($intents as $intent) {
            $sequence->push(['candidates' => [['content' => ['parts' => [['text' => json_encode($intent)]]], 'finishReason' => 'STOP']]]);
        }

        $sequence->whenEmpty(Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(end($intents))]]]]]]));
    }

    public function test_question_is_answered_from_the_systems_own_report(): void
    {
        $this->fakeGemini(['understood' => true, 'report' => 'usage', 'from' => '2026-09-01', 'to' => '2026-09-18', 'limit' => 5]);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Bu ay en çok kullanılan 5 ürün?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee('Kullanım ve Maliyet')
            ->assertSee('01.09.2026 – 18.09.2026')
            ->assertSee('Nitril Eldiven')
            ->assertSee('Gizli Kompozit Z')
            // Kullanım maliyeti: 12·2 + 5·40 = 224 ₺ (rapordan, yapay zekâdan değil).
            ->assertSee('Toplam kullanım maliyeti: 224,00 ₺')
            ->assertSee('raporunda aç');

        $query = AssistantQuery::sole();
        $this->assertSame(AssistantQueryStatus::Answered, $query->status);
        $this->assertSame('usage', $query->intent['report']);
        $this->assertSame('Gemini', $query->provider);
    }

    public function test_only_question_and_filter_options_are_sent_to_the_provider(): void
    {
        $this->fakeGemini(['understood' => true, 'report' => 'stock']);

        Livewire::test('pages::reports.assistant')->set('question', 'Stokta ne var?')->call('ask');

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && str_contains($request->url(), '/models/gemini-3.6-flash:generateContent')
                && str_contains($body, 'Stokta ne var?')
                && str_contains($body, 'Kuzey') && str_contains($body, 'Kompozitler') && str_contains($body, 'Dental Tedarik')
                && $request['generationConfig']['responseSchema']['type'] === 'OBJECT'
                // Stok verisi, ürün listesi, lot ve kişi bilgisi gitmez.
                && ! str_contains($body, 'Gizli Kompozit Z')
                && ! str_contains($body, 'GZ-9')
                && ! str_contains($body, 'LOT-SIR')
                && ! str_contains($body, 'Nitril')
                && ! str_contains($body, $this->admin->email)
                && ! str_contains($body, 'unit_cost')
                && ! str_contains($body, 'quantity');
        });
    }

    public function test_ids_outside_the_users_scope_are_dropped_and_results_stay_in_scope(): void
    {
        $northStaff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $northStaff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        // Yapay zekâ kapsam dışı Merkez şubesini seçse bile uygulanmaz.
        $this->fakeGemini(['understood' => true, 'report' => 'usage', 'branch_id' => $this->central->id, 'category_id' => 999999]);

        $this->actingAs($northStaff);
        Livewire::test('pages::reports.assistant')
            ->set('question', 'Merkezde ne kullanıldı?')
            ->call('ask')
            ->assertSee('Şube filtresi erişiminiz dahilinde bulunamadığı için uygulanmadı.')
            ->assertSee('Kategori filtresi erişiminiz dahilinde bulunamadığı için uygulanmadı.')
            ->assertSee('Nitril Eldiven')
            ->assertDontSee('Gizli Kompozit Z');

        // Kuzey personeline giden istemde Merkez şubesi seçenek olarak da yer almaz.
        Http::assertSent(fn (Request $request) => ! str_contains($request->body(), '"name":"Merkez"'));
    }

    public function test_unrelated_question_gets_a_clarification(): void
    {
        $this->fakeGemini(['understood' => false, 'clarification' => 'Bu soru stok raporlarıyla ilgili değil.']);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Yarın hava nasıl olacak?')
            ->call('ask')
            ->assertSee('Bu soru stok raporlarıyla ilgili değil.');

        $this->assertSame(AssistantQueryStatus::NotUnderstood, AssistantQuery::sole()->status);
    }

    public function test_daily_and_monthly_limits_are_enforced_per_organization(): void
    {
        config(['assistant.daily_limit' => 2]);
        $this->fakeGemini(['understood' => true, 'report' => 'stock']);

        $component = Livewire::test('pages::reports.assistant');
        $component->set('question', 'Stok durumu 1')->call('ask')->assertHasNoErrors();
        $component->set('question', 'Stok durumu 2')->call('ask')->assertHasNoErrors();

        // Aynı organizasyonun başka kullanıcısı da aynı kotayı kullanır.
        $colleague = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($colleague);
        Livewire::test('pages::reports.assistant')
            ->set('question', 'Stok durumu 3')
            ->call('ask')
            ->assertHasErrors(['question'])
            ->assertSee('günlük asistan sorgu limiti doldu');

        Http::assertSentCount(2);

        // Ertesi gün günlük kota yenilenir; aylık kota ayrıca uygulanır.
        $this->travel(1)->days();
        config(['assistant.monthly_limit' => 2]);
        Livewire::test('pages::reports.assistant')
            ->set('question', 'Stok durumu 4')
            ->call('ask')
            ->assertSee('aylık asistan sorgu limiti doldu');

        // Başka organizasyonun kotası etkilenmez.
        $rival = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        Livewire::test('pages::reports.assistant')->set('question', 'Stok durumu')->call('ask')->assertHasNoErrors();
    }

    public function test_provider_errors_are_shown_and_recorded(): void
    {
        Http::fakeSequence(self::GEMINI_URL)
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'bozuk']]]]]]);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Stok durumu')
            ->call('ask')
            ->assertSee('kullanım kotası doldu');

        $this->assertSame(AssistantQueryStatus::Failed, AssistantQuery::sole()->status);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Stok durumu')
            ->call('ask')
            ->assertSee('anlaşılır bir cevap alınamadı');
    }

    public function test_temporary_overload_is_retried(): void
    {
        Sleep::fake();
        Http::fakeSequence(self::GEMINI_URL)
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['understood' => true, 'report' => 'stock'])]]]]]]);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Stok durumu')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee('Stok Durumu');

        Http::assertSentCount(3);
        // Üç deneme tek soru sayılır.
        $this->assertSame(1, AssistantQuery::count());
    }

    public function test_missing_api_key_makes_no_call_and_uses_no_quota(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        Livewire::test('pages::reports.assistant')
            ->assertSee('henüz yapılandırılmadı')
            ->set('question', 'Stok durumu')
            ->call('ask')
            ->assertHasErrors(['question']);

        Http::assertNothingSent();
        $this->assertSame(0, AssistantQuery::count());
    }

    public function test_forecast_and_movement_questions_carry_their_own_filters(): void
    {
        $this->fakeGemini(
            ['understood' => true, 'report' => 'forecast', 'risk' => 'no_usage', 'search' => 'kompozit'],
            ['understood' => true, 'report' => 'movements', 'movement_type' => 'in', 'from' => '2026-09-18', 'to' => '2026-09-18'],
        );

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Kompozit hiç kullanılmıyor mu?')
            ->call('ask')
            ->assertSee('Tüketim Tahmini')
            ->assertSee('Ürün: &quot;kompozit&quot;', false)
            ->assertDontSee('Nitril Eldiven');

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Bugün hangi girişler oldu?')
            ->call('ask')
            ->assertSee('Hareket: Giriş')
            ->assertSee('2 hareket (Giriş)')
            ->assertSee('Net değişim: 130');
    }

    public function test_open_in_report_link_restores_filters_on_the_report_page(): void
    {
        Livewire::withQueryParams(['from' => '2026-08-01', 'to' => '2026-08-31', 'branchId' => (string) $this->north->id, 'categoryId' => (string) $this->category->id])
            ->test('pages::reports.usage')
            ->assertSet('from', '2026-08-01')
            ->assertSet('to', '2026-08-31')
            ->assertSet('branchId', (string) $this->north->id)
            ->assertSet('categoryId', (string) $this->category->id);

        Livewire::withQueryParams(['from' => 'bozuk'])->test('pages::reports.usage')->assertSet('from', '2026-09-01');
    }

    public function test_access_rules(): void
    {
        $this->fakeGemini(['understood' => true, 'report' => 'stock']);

        $stockOnly = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $stockOnly->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($stockOnly)->get('/raporlar/asistan')->assertForbidden();

        // Salt-okunur organizasyon rapor sorabilir (okuma işlemi).
        $this->organization->update(['status' => 'read_only']);
        $this->actingAs($this->admin->fresh());
        $this->get('/raporlar/asistan')->assertOk();
        Livewire::test('pages::reports.assistant')->set('question', 'Stok durumu')->call('ask')->assertHasNoErrors();
        $this->assertSame(AssistantQueryStatus::Answered, AssistantQuery::sole()->status);
    }
}

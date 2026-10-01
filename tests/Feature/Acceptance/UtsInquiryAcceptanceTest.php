<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 33 kabul: token gir → bağlantıyı dene → bekleyenleri gör → lot doğrula.
 * Gerçek HttpUtsClient kullanılır; ÜTS Http::fake ile taklit edilir.
 */
class UtsInquiryAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_connects_then_sees_pending_and_verifies_a_lot(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        Product::create(['organization_id' => $org->id, 'name' => 'Implant X', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994']);
        $this->actingAs($admin);

        Http::fake([
            '*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => [
                ['GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1', 'GKU' => 'Tedarik A.Ş.'],
            ]]),
            '*/tekilUrun/sorgula' => Http::response(['SNC' => [
                ['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 2, 'SKT' => '2028-01-31'],
            ]]),
        ]);

        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'test')
            ->set('utsToken', 'tok')
            ->call('saveUts')
            ->call('testUts')
            ->assertSee('Bağlantı başarılı');

        Livewire::test('pages::uts.pending')->assertSee('IRS-1')->assertSee('Ürün bizde var');

        Livewire::test('pages::uts.verify')
            ->set('uno', '08699999999994')
            ->set('lot', 'L1')
            ->call('check')
            ->assertSee('ÜTS\'de kayıtlı', false);

        // Bu aşama ÜTS'ye hiçbir şey yazmaz: yalnızca iki sorgu servisi çağrılır.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bildirim/alma/bekleyenler/sorgula'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tekilUrun/sorgula'));
        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), '/sorgula'));
        Http::assertNotSent(fn (Request $r) => $r->header('utsToken') !== ['tok']);
    }
}

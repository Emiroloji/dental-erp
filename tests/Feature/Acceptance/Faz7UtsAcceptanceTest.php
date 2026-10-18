<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 7 kabul (ÜTS'yi tamamlama): saatlik tarama yeni kabul bekleyeni
 * bildirir → biri "Almak istemiyorum", diğeri kabul → tedarikçiye iadede
 * Verme → SKT geçmiş çıkışta İmha → geçmişte imha iptali. Gerçek
 * HttpUtsClient; ÜTS Http::fake ile taklit edilir.
 */
class Faz7UtsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uts_lifecycle_from_pending_to_disposal(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $supplier = Supplier::create(['name' => 'Anadolu Dental', 'tax_number' => '1234567890', 'status' => 'active']);
        $implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        app(StockMovementService::class)->in($implant, $warehouse, 3, ['lot_no' => 'IMP-1', 'expiry_date' => '2026-01-01'], $admin, tracking: ['serials' => ['S1', 'S2', 'S3']]);

        $pending = [
            ['BID' => 'v1', 'GKK' => 12345, 'GKU' => 'Anadolu Dental', 'UNO' => '08699999999994', 'SNO' => 'S9', 'ADT' => 1, 'BNO' => 'ADM-1'],
            ['BID' => 'v2', 'GKK' => 12345, 'GKU' => 'Anadolu Dental', 'UNO' => '08699999999994', 'SNO' => 'S8', 'ADT' => 1, 'BNO' => 'ADM-1'],
        ];
        Http::fake([
            '*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => $pending]),
            '*/almakIstemiyorum/almakIstemiyorumOlarakIsaretle' => Http::response(['MSJ' => [['TIP' => 'BILGI', 'MET' => 'İşaretlendi.']]]),
            '*/bildirim/alma/ekle' => Http::response(['SNC' => 'alma-1']),
            '*/bildirim/verme/ekle' => Http::response(['SNC' => 'verme-1']),
            '*/bildirim/imhaBertaraf/ekle' => Http::response(['SNC' => 'imha-1']),
            '*/bildirim/imhaBertaraf/iptal' => Http::response(['SNC' => 'imha-iptal-1']),
        ]);

        Livewire::test('pages::settings.index')->set('utsEnvironment', 'test')->set('utsToken', 'tok')->call('saveUts');

        // 1) Saatlik tarama (testte kuyruk eşzamanlı) yeni kabul bekleyenleri bildirir.
        $this->artisan('uts:scan-pending')->assertSuccessful();
        $this->assertStringContainsString('2 yeni Verme bildirimi', $admin->notifications()->sole()->data['message']);

        // 2) Biri "Almak istemiyorum", diğeri kabul.
        Livewire::test('pages::uts.pending')->call('confirmDecline', 'v1')->call('decline');
        Livewire::test('pages::uts.pending')->call('confirm', 'v2')->call('send')->assertSee('alma-1');

        // 3) Tedarikçiye iade kargolanır ve ÜTS'ye Verme olarak bildirilir.
        $returns = app(ReturnService::class);
        $return = $returns->request(StockLot::where('product_id', $implant->id)->sole(), 1, ReturnReason::Defective, $supplier, $admin, ['serials' => ['S1']]);
        $returns->approve($return, $admin);
        $returns->ship($return->fresh(), $admin);
        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->set('utsInstitutionNo', '12345')->set('utsDocumentNo', 'IADE-1')->call('reportToUts')->assertHasNoErrors();

        // 4) SKT geçmiş iki implant imha edilir ve ÜTS'ye bildirilir.
        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $implant->id)
            ->set('warehouse_id', (string) $warehouse->id)
            ->set('selectedSerials', ['S2', 'S3'])
            ->set('reasonCategory', 'expired')
            ->set('disposalDocumentNo', 'IMHA-1')
            ->call('save')
            ->assertHasNoErrors();

        // 5) Geçmişte bir imha bildirimi iptal edilir.
        $imha = UtsNotification::where('type', 'imha')->orderBy('id')->first();
        Livewire::test('pages::uts.history')->call('confirmCancel', $imha->id)->call('cancelInUts');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/almakIstemiyorum/almakIstemiyorumOlarakIsaretle') && $r['BID'] === 'v1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/alma/ekle') && $r['VBI'] === 'v2');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/verme/ekle') && $r->data() === ['UNO' => '08699999999994', 'SNO' => 'S1', 'KUN' => 12345, 'BNO' => 'IADE-1', 'GIT' => now()->toDateString()]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/imhaBertaraf/ekle') && $r['SNO'] === 'S2' && $r['GRK'] === 'SON_KULLANMA_TARIHI_GECMIS' && $r['BNO'] === 'IMHA-1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/imhaBertaraf/iptal') && $r['BID'] === 'imha-1');
        Http::assertNotSent(fn (Request $r) => $r->header('utsToken') !== ['tok']);

        $this->assertSame(
            ['almak_istemiyorum', 'alma', 'verme', 'imha', 'imha', 'imha_iptal'],
            UtsNotification::orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame(12345, $supplier->fresh()->uts_institution_no);
    }
}

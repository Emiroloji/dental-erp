<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 43 kabul: hasta ekle → implantı hastaya kullanımla çıkar → ÜTS'ye
 * Kullanım bildirimi → hasta kartında gör → ÜTS'de iptal et → stok
 * çıkışını iptal et. Gerçek HttpUtsClient; ÜTS Http::fake ile taklit edilir.
 */
class PatientUsageAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_implant_used_on_a_patient_is_reported_and_can_be_reverted(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        Http::fake([
            'utstest.saglik.gov.tr/*/bildirim/kullanim/ekle' => Http::response(['SNC' => 'kullanim-kodu-1', 'MSJ' => [['TIP' => 'BILGI', 'KOD' => 'UTS-B006', 'MET' => 'Kullanım bildirimi yapıldı.']]]),
            'utstest.saglik.gov.tr/*/bildirim/kullanim/iptal' => Http::response(['SNC' => 'iptal-kodu-1']),
        ]);

        Livewire::test('pages::settings.index')->set('utsEnvironment', 'test')->set('utsToken', 'tok')->call('saveUts');

        // Ürün kartı: seri takipli, GTIN'li, hastaya kullanımda ÜTS'ye bildirilir.
        $implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        app(StockMovementService::class)->in($implant, $warehouse, 1, ['lot_no' => 'IMP-1'], $admin, tracking: ['serials' => ['SN-1']]);

        Livewire::test('pages::patients.index')
            ->call('openForm')
            ->set('first_name', 'Ayşe')->set('last_name', 'Yılmaz')
            ->set('identity_type', 'tc')->set('identity_no', '10000000146')
            ->call('save');
        $patient = Patient::sole();

        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $implant->id)
            ->set('warehouse_id', (string) $warehouse->id)
            ->set('selectedSerials', ['SN-1'])
            ->set('reasonCategory', 'clinical_use')
            ->set('patientSearch', '10000000146')
            ->assertSee('Ayşe Yılmaz')
            ->call('selectPatient', $patient->id)
            ->set('used_on', '2026-10-18')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/kullanim/ekle'
            && $r->data() === ['UNO' => '08699999999994', 'SNO' => 'SN-1', 'GIT' => '2026-10-18', 'HAA' => 'Ayşe', 'HAS' => 'Yılmaz', 'TUR' => 'TC_KIMLIK_NUMARASI_VAR', 'TKN' => '10000000146']
            && $r->header('utsToken') === ['tok']);

        $this->get("/hastalar/{$patient->id}")->assertSee('Implant 4.1')->assertSee('SN-1')->assertDontSee('10000000146');

        $movement = StockMovement::where('type', 'out')->sole();
        Livewire::test('pages::stock.movements')->call('cancel', $movement->id);
        $this->assertSame(0, StockMovement::where('type', 'cancel')->count());

        Livewire::test('pages::uts.history')
            ->call('confirmCancel', UtsNotification::where('type', 'kullanim')->sole()->id)
            ->call('cancelInUts');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/kullanim/iptal') && $r->data() === ['BID' => 'kullanim-kodu-1']);

        Livewire::test('pages::stock.movements')->call('cancel', $movement->id);
        $this->assertSame(1, StockMovement::where('type', 'cancel')->count());
        $this->assertNotNull(PatientUsage::sole()->cancelled_at);
        $this->assertSame('cancelled', PatientUsage::sole()->utsStatus());
    }
}

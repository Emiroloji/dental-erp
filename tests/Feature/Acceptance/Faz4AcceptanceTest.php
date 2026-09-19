<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Access\Support\Module;
use App\Domain\Assistant\Models\AssistantQuery;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Forecasting\Services\ConsumptionForecastService;
use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Forecasting\Support\ProductForecast;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Transfer\Models\TransferRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 27 — Faz 4 Uçtan Uca Kabul Testi. Faz 4'ün üç parçası (tüketim
 * tahmini, doğal dille rapor sorgulama, ilaç/medikal genişletmesi) tek bir
 * zincirde, seeder'dan sonraki her adım ekranlardan (Livewire + HTTP)
 * yürütülür. Zaman 17.08.2026'dan 18.09.2026'ya ilerler: dört haftalık
 * kullanım geçmişi tahminin girdisidir.
 *
 * Zincir boyunca stok, tek giriş noktası olan StockMovementService'ten geçer;
 * sonunda her lotun hareket defteri toplamı lot miktarına, seri takipli
 * lotlarda da stoktaki seri sayısına eşit olmalıdır.
 */
class Faz4AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const GTIN = '04006381333931';

    private Organization $clinic;

    private User $admin;

    private Warehouse $central;

    private Warehouse $north;

    private Supplier $supplier;

    private Product $gloves;

    private Product $anesthetic;

    private Product $implant;

    public function test_faz4_end_to_end_acceptance(): void
    {
        $this->seed();
        $this->travelTo('2026-08-17 09:00:00');

        $this->step1_admin_signs_in_and_opens_a_second_branch();
        $this->step2_medical_product_cards_are_defined();
        $this->step3_purchase_receipt_records_serials_and_temperature();
        $this->step4_out_of_range_cold_chain_entry_needs_an_explanation();
        $this->step5_four_weeks_of_usage_through_the_screens();
        $this->step6_consumption_forecast_matches_the_hand_computed_value();
        $this->step7_implant_transfer_carries_its_serial_and_history();
        $this->step8_defective_implant_is_returned_by_serial();
        $this->step9_serial_count_finds_an_unregistered_unit();
        $this->step10_controlled_product_ledger_balances();
        $this->step11_report_assistant_answers_from_the_systems_own_numbers();
        $this->step12_every_lot_matches_its_ledger_and_serials();
        $this->step13_other_organizations_see_nothing();
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment));
    }

    private function step1_admin_signs_in_and_opens_a_second_branch(): void
    {
        Livewire::test('pages::access.login')->set('email', 'admin@dental-erp.test')->set('password', 'password')->call('login')
            ->assertRedirect(route('dashboard'));
        $this->admin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->clinic = $this->admin->organization;
        $this->actingAs($this->admin);

        Livewire::test('pages::organization.branches')->call('create')->set('name', 'Kuzey Şubesi')->call('save')->assertHasNoErrors();

        $this->central = Warehouse::whereHas('branch', fn ($query) => $query->where('name', 'Merkez Şube'))->sole();
        $this->north = Warehouse::whereHas('branch', fn ($query) => $query->where('name', 'Kuzey Şubesi'))->sole();

        Livewire::test('pages::catalog.suppliers')->call('openForm')->set('name', 'Medikal Tedarik A.Ş.')->call('save')->assertHasNoErrors();
        $this->supplier = Supplier::where('name', 'Medikal Tedarik A.Ş.')->sole();
    }

    private function step2_medical_product_cards_are_defined(): void
    {
        $form = fn () => Livewire::test('pages::catalog.products')->call('openForm');

        $form()->set('name', 'Nitril Eldiven')->set('barcode', 'ELD-01')->set('min_stock', '20')->call('save')->assertHasNoErrors();

        $form()->set('name', 'Artikain Anestezik')->set('base_unit', 'Kartuş')->set('product_type', 'medicine')
            ->set('license_number', 'RUH-2024/15')->set('uts_number', 'UTS-ART')->set('manufacturer', 'Örnek İlaç')
            ->set('cold_chain', true)->set('storage_min_temp', '2')->set('storage_max_temp', '8')
            ->set('is_controlled', true)
            ->call('save')->assertHasNoErrors();

        $form()->set('name', 'İmplant 4.1x10')->set('product_type', 'equipment')->set('gtin', '4006381333931')
            ->set('tracks_serials', true)
            ->call('save')->assertHasNoErrors();

        $this->gloves = Product::where('name', 'Nitril Eldiven')->sole();
        $this->anesthetic = Product::where('name', 'Artikain Anestezik')->sole();
        $this->implant = Product::where('name', 'İmplant 4.1x10')->sole();

        $this->assertSame(self::GTIN, $this->implant->gtin);
        $this->get('/urunler')->assertSee('2–8 °C')->assertSee('Kontrollü')->assertSee('Seri takipli');
    }

    private function step3_purchase_receipt_records_serials_and_temperature(): void
    {
        Livewire::test('pages::purchasing.index')->call('create')
            ->set('supplier_id', (string) $this->supplier->id)
            ->set('warehouse_id', (string) $this->central->id)
            ->set('lines.0.product_id', (string) $this->gloves->id)->set('lines.0.quantity', '200')->set('lines.0.unit_price', '2')
            ->call('addLine')
            ->set('lines.1.product_id', (string) $this->anesthetic->id)->set('lines.1.quantity', '30')->set('lines.1.unit_price', '15')
            ->call('addLine')
            ->set('lines.2.product_id', (string) $this->implant->id)->set('lines.2.quantity', '3')->set('lines.2.unit_price', '900')
            ->call('save', true)->assertHasNoErrors();

        $order = PurchaseOrder::sole();
        Livewire::test('pages::purchasing.show', ['order' => $order->id])->call('approve');
        Livewire::test('pages::purchasing.show', ['order' => $order->id])->call('markOrdered');

        $line = fn (Product $product) => $order->lines()->where('product_id', $product->id)->sole()->id;
        [$glovesLine, $anestheticLine, $implantLine] = [$line($this->gloves), $line($this->anesthetic), $line($this->implant)];

        $receipt = Livewire::withQueryParams(['teslim' => $order->id])->test('pages::purchasing.index')
            ->assertSet('receivingId', $order->id)
            ->set("receiptLines.{$glovesLine}.lot_no", 'E-1')
            ->set("receiptLines.{$anestheticLine}.lot_no", 'ART-1')->set("receiptLines.{$anestheticLine}.expiry_date", '2027-12-31')
            ->set("receiptLines.{$implantLine}.lot_no", 'IMP-2026')->set("receiptLines.{$implantLine}.expiry_date", '2031-06-30')
            ->set('invoice_number', 'FTR-0817')
            ->call('receive')
            // Soğuk zincir sıcaklığı ve implant serileri girilmeden teslim alınamaz.
            ->assertHasErrors(["receiptLines.{$anestheticLine}.temperature", "receiptLines.{$implantLine}.serials"]);

        $receipt->set("receiptLines.{$anestheticLine}.temperature", '5')
            ->set("receiptLines.{$implantLine}.serials", "IMP-A\nIMP-B\nIMP-C")
            ->call('receive')->assertHasNoErrors();

        $this->assertEquals(200, StockLot::where('lot_no', 'E-1')->sole()->quantity);
        $this->assertSame(5.0, StockMovement::whereHas('lot', fn ($query) => $query->where('lot_no', 'ART-1'))->sole()->temperature);
        $this->assertSame(['IMP-A', 'IMP-B', 'IMP-C'], StockSerial::where('status', 'in_stock')->orderBy('serial_no')->pluck('serial_no')->all());
    }

    private function step4_out_of_range_cold_chain_entry_needs_an_explanation(): void
    {
        $this->anesthetic->update(['barcode' => 'ART-BARKOD']);

        $quick = Livewire::test('pages::stock.quick')
            ->set('mode', 'in')
            ->set('warehouse_id', (string) $this->central->id)
            ->set('scanCode', 'ART-BARKOD')->call('scan')
            ->set('quantity', '10')->set('lot_no', 'ART-2')->set('expiry_date', '2027-06-30')
            ->set('temperature', '11')
            ->call('submit')
            ->assertHasErrors(['temperature_note']);

        $this->assertSame(0, StockLot::where('lot_no', 'ART-2')->count());

        $quick->set('temperature_note', 'Kargo gecikmesi; üretici stabilite onayı alındı')->call('submit')->assertHasNoErrors();

        $accepted = StockMovement::whereHas('lot', fn ($query) => $query->where('lot_no', 'ART-2'))->sole();
        $this->assertSame(11.0, $accepted->temperature);
        $this->assertSame('Kargo gecikmesi; üretici stabilite onayı alındı', $accepted->temperature_note);
    }

    private function step5_four_weeks_of_usage_through_the_screens(): void
    {
        // Eldiven: haftalık 20, 24, 28, 32 (artan) — barkodla Hızlı İşlem.
        foreach (['2026-08-25' => 20, '2026-09-01' => 24, '2026-09-08' => 28, '2026-09-15' => 32] as $day => $quantity) {
            $this->at("{$day} 11:00:00");
            Livewire::test('pages::stock.quick')
                ->set('mode', 'out')->set('warehouse_id', (string) $this->central->id)
                ->set('scanCode', 'ELD-01')->call('scan')
                ->set('quantity', (string) $quantity)->set('reasonCategory', 'clinical_use')
                ->call('submit')->assertHasNoErrors();
        }

        // Kontrollü anestezik: açıklamasız çıkış reddedilir.
        $this->at('2026-09-02 10:00:00');
        Livewire::test('pages::stock.out')->call('openForm')
            ->set('product_id', (string) $this->anesthetic->id)->set('warehouse_id', (string) $this->central->id)
            ->set('quantity', '2')->set('reasonCategory', 'clinical_use')
            ->call('save')->assertHasErrors(['reasonNote'])
            ->set('reasonNote', 'Dr. Deniz — cerrahi çekim')
            ->call('save')->assertHasNoErrors();

        // İmplant: kutudaki GS1 kodu okutulur, o seri çıkar.
        $this->at('2026-09-10 14:00:00');
        Livewire::test('pages::stock.quick')
            ->set('mode', 'out')->set('warehouse_id', (string) $this->central->id)
            ->set('scanCode', '01'.self::GTIN."17310630\x1D10IMP-2026\x1D21IMP-A")->call('scan')
            ->assertSet('serialsText', 'IMP-A')->assertSet('quantity', '1')
            ->set('reasonCategory', 'clinical_use')
            ->call('submit')->assertHasNoErrors();

        $this->at('2026-09-18 12:00:00');
        $this->assertEquals(96, StockLot::where('lot_no', 'E-1')->sole()->quantity);
        $this->assertSame(SerialStatus::Out, StockSerial::where('serial_no', 'IMP-A')->sole()->status);
    }

    private function step6_consumption_forecast_matches_the_hand_computed_value(): void
    {
        $forecast = app(ConsumptionForecastService::class)
            ->forecast($this->clinic->id, new ReportFilters, $this->admin->accessibleBranchIds(Module::Reports))
            ->first(fn (ProductForecast $forecast) => $forecast->product->is($this->gloves));

        // Holt([20, 24, 28, 32], α=0,5, β=0,3) = 36/hafta → 36/7 gün⁻¹; 96 / 5,14 = 18,7 gün.
        $this->assertEqualsWithDelta(36 / 7, $forecast->dailyRate, 0.0001);
        $this->assertSame(ForecastRisk::Soon, $forecast->risk);
        $this->assertSame('2026-10-06', $forecast->stockoutDate->toDateString());
        // Min. stok 20: (96 − 20) / 5,14 = 14,8 → 14 gün sonra sipariş zamanı.
        $this->assertSame('2026-10-02', $forecast->minStockDate->toDateString());

        $this->get('/raporlar/tahmin?risk=soon')->assertOk()->assertSee('Nitril Eldiven')->assertSee('06.10.2026')->assertSee('02.10.2026');
    }

    private function step7_implant_transfer_carries_its_serial_and_history(): void
    {
        Livewire::test('pages::transfer.index')->call('openForm')
            ->set('product_id', (string) $this->implant->id)
            ->set('to_warehouse_id', (string) $this->north->id)
            ->set('from_warehouse_id', (string) $this->central->id)
            ->set('quantity', '1')->set('reason', 'Kuzey\'de implant vakası')
            ->call('save')->assertHasNoErrors();

        $transfer = TransferRequest::sole();
        foreach (['approve', 'prepare', 'ship', 'receive'] as $action) {
            Livewire::test('pages::transfer.show', ['transfer' => $transfer->id])->call($action);
        }

        $moved = StockSerial::where('serial_no', 'IMP-B')->sole();
        $this->assertSame(SerialStatus::InStock, $moved->status);
        $this->assertSame($this->north->id, $moved->lot->warehouse_id);

        $this->get(route('transfers.show', $transfer))->assertSee('Seri: IMP-B');
        $this->get('/seri-takibi?seri=IMP-B')->assertOk()
            ->assertSee('Transfer (Gönderim)')->assertSee('Transfer (Teslim Alım)')->assertSee('Kuzey Şubesi');
    }

    private function step8_defective_implant_is_returned_by_serial(): void
    {
        $lot = StockLot::where('lot_no', 'IMP-2026')->where('warehouse_id', $this->central->id)->sole();

        Livewire::test('pages::returns.index')->call('openForm')
            ->set('warehouse_id', (string) $this->central->id)
            ->set('product_id', (string) $this->implant->id)
            ->set('lot_id', (string) $lot->id)
            ->set('selectedSerials', ['IMP-C'])
            ->set('reason', ReturnReason::Defective->value)
            ->set('supplier_id', (string) $this->supplier->id)
            ->call('save')->assertHasNoErrors();

        $return = SupplierReturn::sole();
        Livewire::test('pages::returns.show', ['return' => $return->id])->call('approve');
        Livewire::test('pages::returns.show', ['return' => $return->id])->set('actionNote', 'Kargo takip 123')->call('ship');

        $this->assertSame(SerialStatus::Out, StockSerial::where('serial_no', 'IMP-C')->sole()->status);
        $this->assertEquals(0, $lot->fresh()->quantity);
    }

    private function step9_serial_count_finds_an_unregistered_unit(): void
    {
        Livewire::test('pages::inventory.index')->call('openStart')
            ->set('warehouse_id', (string) $this->north->id)->call('start');

        $count = StockCount::sole();
        $line = $count->lines()->sole();

        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->set("entries.{$line->id}.counted_serials", "IMP-B\nIMP-X")
            ->set("entries.{$line->id}.reason", 'record_error')
            ->set("entries.{$line->id}.note", 'Rafta kayıtsız bir birim bulundu')
            ->call('submit');
        Livewire::test('pages::inventory.show', ['count' => $count->id])->call('approve');

        $this->assertSame(SerialStatus::InStock, StockSerial::where('serial_no', 'IMP-X')->sole()->status);
        $this->assertEquals(2, StockLot::where('warehouse_id', $this->north->id)->sole()->quantity);
    }

    private function step10_controlled_product_ledger_balances(): void
    {
        $this->get('/raporlar/kontrollu?from=2026-08-01&to=2026-09-18')->assertOk()
            ->assertSee('Artikain Anestezik')
            ->assertSee('Ruhsat RUH-2024/15')
            ->assertSee('Dr. Deniz — cerrahi çekim')
            // 0 → +30 (teslim) → +10 (aralık dışı kabul) → −2 (kullanım) = 38.
            ->assertSeeInOrder(['Açılış 0', 'Kapanış', '38']);
    }

    private function step11_report_assistant_answers_from_the_systems_own_numbers(): void
    {
        config(['services.gemini.key' => 'kabul-testi-anahtari']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['understood' => true, 'report' => 'forecast', 'risk' => 'soon'])]]]]],
        ])]);

        Livewire::test('pages::reports.assistant')
            ->set('question', 'Yakında tükenecek ürünler hangileri?')
            ->call('ask')->assertHasNoErrors()
            ->assertSee('Durum: Yakında')
            ->assertSee('Nitril Eldiven')
            ->assertSee('06.10.2026')
            ->assertSee('bugün 1/50');

        // Gövde JSON olduğu için Türkçe karakterler kaçışlı gelir; içerik çözülerek incelenir.
        Http::assertSent(fn (Request $request) => str_contains($body = json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'Yakında tükenecek ürünler hangileri?')
            && str_contains($body, 'Kuzey Şubesi')
            && ! str_contains($body, 'Nitril')
            && ! str_contains($body, 'Artikain')
            && ! str_contains($body, 'IMP-')
            && ! str_contains($body, 'E-1'));

        $this->assertSame(1, AssistantQuery::where('organization_id', $this->clinic->id)->count());
    }

    private function step12_every_lot_matches_its_ledger_and_serials(): void
    {
        foreach (StockLot::with('product')->get() as $lot) {
            $ledger = (float) StockMovement::where('lot_id', $lot->id)->sum('quantity');
            $this->assertEqualsWithDelta((float) $lot->quantity, $ledger, 0.0001, "Lot {$lot->lot_no}: defter toplamı ≠ lot miktarı");

            if ($lot->product->tracks_serials) {
                $this->assertSame((int) $lot->quantity, StockSerial::where('lot_id', $lot->id)->where('status', 'in_stock')->count(), "Lot {$lot->lot_no}: stoktaki seri sayısı ≠ lot miktarı");
            }
        }

        // İmplant özeti: A kullanıldı, B Kuzey'e gitti, C iade edildi, X sayımda bulundu.
        $this->assertSame(
            ['IMP-A' => 'out', 'IMP-B' => 'in_stock', 'IMP-C' => 'out', 'IMP-X' => 'in_stock'],
            StockSerial::orderBy('serial_no')->pluck('status', 'serial_no')->map->value->all(),
        );
    }

    private function step13_other_organizations_see_nothing(): void
    {
        $rivalAdmin = User::factory()->create([
            'organization_id' => Organization::create(['name' => 'Rakip Klinik', 'status' => 'active', 'plan' => 'professional'])->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);
        $rivalBranch = Branch::create(['organization_id' => $rivalAdmin->organization_id, 'name' => 'Rakip Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $rivalBranch->id, 'name' => 'Rakip Depo', 'is_default' => true, 'status' => 'active']);
        $this->actingAs($rivalAdmin);

        $this->get('/raporlar/tahmin')->assertOk()->assertDontSee('Nitril Eldiven');
        $this->get('/raporlar/kontrollu?from=2026-08-01')->assertOk()->assertDontSee('Artikain');
        $this->get('/seri-takibi?seri=IMP')->assertOk()->assertDontSee('IMP-B')->assertSee('eşleşen seri numarası bulunamadı');
        $this->get('/urunler')->assertDontSee('İmplant 4.1x10');
        $this->get(route('transfers.show', TransferRequest::withoutGlobalScopes()->sole()))->assertNotFound();
        $this->get(route('returns.show', SupplierReturn::withoutGlobalScopes()->sole()))->assertNotFound();

        // Bizim ürünün GS1 kodu rakipte hiçbir ürüne çözülmez.
        Livewire::test('pages::stock.quick')->set('scanCode', '01'.self::GTIN.'21IMP-B')->call('scan')
            ->assertSet('productId', null)->assertSee('ile kayıtlı bir ürün yok');

        // Asistan kotası organizasyona özeldir.
        Livewire::test('pages::reports.assistant')->assertSee('bugün 0/50');
    }
}

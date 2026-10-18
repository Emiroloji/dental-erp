<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 6 kabul: e-Fatura anahtarını gir → zamanlanmış eşitleme faturayı çeker
 * ve okur → listede gör → detayda tedarikçi ekle, ürün kartı ve ÜTS kabul
 * bekleyenleri gör → Finans'a cari borç aktar. Gerçek NilveraProvider ve
 * HttpUtsClient; dış servisler Http::fake ile taklit edilir.
 */
class Faz6EInvoiceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_invoice_flows_from_provider_to_finance(): void
    {
        Storage::fake('local');
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);
        Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994']);

        Http::fake([
            'apitest.nilvera.com/einvoice/Purchase?*' => Http::response(['Page' => 1, 'PageSize' => 50, 'TotalPages' => 1, 'Content' => [
                ['UUID' => '5d59e861-0ecc-11e7-a708-f52ed20ddd29', 'InvoiceNumber' => 'ADM2026000451', 'SenderTaxNumber' => '1234567890', 'SenderName' => 'Anadolu Dental Medikal Ltd. Şti.'],
            ]]),
            'apitest.nilvera.com/einvoice/Purchase/5d59e861-0ecc-11e7-a708-f52ed20ddd29/xml' => Http::response(file_get_contents(base_path('tests/Fixtures/einvoice/tibbi-cihaz.xml'))),
            'utstest.saglik.gov.tr/*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => [
                ['BID' => 'v1', 'GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'IMP2026A', 'SNO' => 'SN-778812', 'ADT' => 1, 'BNO' => 'ADM2026000451'],
            ]]),
        ]);

        Livewire::test('pages::settings.index')
            ->set('einvoiceEnvironment', 'test')->set('einvoiceApiKey', 'nilvera-anahtar')->call('saveEInvoice')
            ->set('utsEnvironment', 'test')->set('utsToken', 'uts-token')->call('saveUts');

        // Zamanlayıcının çalıştırdığı komut; testte kuyruk eşzamanlıdır.
        $this->artisan('einvoice:sync')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://apitest.nilvera.com/einvoice/Purchase?') && $r->header('Authorization') === ['Bearer nilvera-anahtar']);

        $invoice = EInvoice::sole();
        $this->assertSame(2370500, $invoice->payable);

        Livewire::test('pages::einvoice.index')->assertSee('ADM2026000451')->assertSee('Tedarikçi yok');

        Livewire::test('pages::einvoice.show', ['invoice' => $invoice->id])
            ->assertSee('Ürün kartı: Implant 4.1')
            ->assertSee('ÜTS\'de bu faturaya ait 1 kabul bekleyen kayıt var', false)
            ->call('createSupplier')
            ->call('openTransfer')
            ->set('dueOn', '2026-11-16')
            ->call('transfer')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bildirim/alma/bekleyenler/sorgula') && $r['BNO'] === 'ADM2026000451');

        $this->assertSame(Supplier::sole()->id, $invoice->fresh()->supplier_id);
        $obligation = FinanceObligation::sole();
        $this->assertSame(2370500, $obligation->amount);
        $this->assertSame('ADM2026000451', $obligation->document_no);
        $this->assertSame('1234567890', $obligation->party->tax_number);
        $this->assertSame($obligation->id, $invoice->fresh()->finance_obligation_id);

        // Hiçbir ÜTS yazma isteği gönderilmedi: kabul kullanıcının onayıyla ÜTS ekranından yapılır.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/alma/ekle'));
    }
}

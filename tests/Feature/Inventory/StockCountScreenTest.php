<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Exports\TableExport;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StockCountScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $kadikoy;

    private Warehouse $warehouse;

    private User $admin;

    private User $counter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->kadikoy = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->kadikoy->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->counter = $this->staff('Depo Sayımcısı', $this->kadikoy);
    }

    private function staff(string $name, Branch $branch, bool $write = true): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'name' => $name, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => 'own_branch']);

        return $staff->fresh();
    }

    /**
     * @return array<int, StockLot>
     */
    private function stock(int $lots, float $quantity = 10): array
    {
        $this->actingAs($this->admin);
        $created = [];
        for ($i = 1; $i <= $lots; $i++) {
            $product = Product::create(['name' => sprintf('Ürün %02d', $i), 'base_unit' => 'Adet', 'status' => 'active']);
            app(StockMovementService::class)->in($product, $this->warehouse, $quantity, ['lot_no' => sprintf('LOT-%02d', $i)], $this->admin);
            $created[] = StockLot::where('product_id', $product->id)->sole();
        }

        return $created;
    }

    public function test_count_is_started_entered_submitted_and_approved_from_the_screens(): void
    {
        [$lot] = $this->stock(1, 100);

        $this->actingAs($this->counter);
        Livewire::test('pages::inventory.index')
            ->call('openStart')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('note', 'Ay sonu')
            ->call('start')
            ->assertRedirect(route('inventory.show', StockCount::sole()));

        $count = StockCount::sole();
        $line = $count->lines()->sole();

        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('LOT-01')
            ->set("entries.{$line->id}.counted_quantity", '97')
            ->set("entries.{$line->id}.reason", 'record_error')
            ->set("entries.{$line->id}.note", 'kullanım kaydı eksik')
            ->call('submit')
            ->assertDontSee('Onayla');

        $this->assertSame(StockCountStatus::PendingApproval, $count->fresh()->status);
        Livewire::test('pages::inventory.show', ['count' => $count->id])->call('approve')->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('-3,00')
            ->call('approve');

        $this->assertSame(StockCountStatus::Approved, $count->fresh()->status);
        $this->assertSame(97.0, (float) $lot->fresh()->quantity);
    }

    public function test_submission_problems_are_shown_to_the_user(): void
    {
        $this->stock(1, 100);
        $count = app(StockCountService::class)->start($this->warehouse, $this->counter);
        $line = $count->lines()->sole();

        $this->actingAs($this->counter);
        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->set("entries.{$line->id}.counted_quantity", '97')
            ->call('submit')
            ->assertSee('fark için bir neden seçin');

        $this->assertSame(StockCountStatus::Counting, $count->fresh()->status);
        $this->assertSame(97.0, (float) $line->fresh()->counted_quantity, 'girilen sayım kaybolmaz');
    }

    public function test_entries_survive_moving_between_pages(): void
    {
        $lots = $this->stock(30);
        $count = app(StockCountService::class)->start($this->warehouse, $this->counter);
        $lineFor = fn (StockLot $lot) => $count->lines()->where('stock_lot_id', $lot->id)->sole();

        $this->actingAs($this->counter);
        $component = Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('LOT-01')
            ->assertDontSee('LOT-30')
            ->set('entries.'.$lineFor($lots[0])->id.'.counted_quantity', '10')
            ->call('nextPage')
            ->assertSee('LOT-30');

        $this->assertSame(10.0, (float) $lineFor($lots[0])->counted_quantity, 'sayfa değişirken kaydedildi');

        $component->set('entries.'.$lineFor($lots[29])->id.'.counted_quantity', '10')->call('save');
        $this->assertSame(10.0, (float) $lineFor($lots[29])->counted_quantity);
    }

    public function test_counts_are_scoped_and_read_only_staff_cannot_start_one(): void
    {
        $this->stock(1);
        $count = app(StockCountService::class)->start($this->warehouse, $this->counter);

        $besiktas = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $outsider = $this->staff('Beşiktaşlı', $besiktas);
        $this->actingAs($outsider)->get('/stok-sayimi')->assertOk()->assertDontSee($count->number());
        $this->get(route('inventory.show', $count))->assertNotFound();

        $reader = $this->staff('Okur', $this->kadikoy, write: false);
        $this->actingAs($reader)->get('/stok-sayimi')->assertOk()->assertSee($count->number())->assertDontSee('Sayım Başlat');
        $this->get(route('inventory.show', $count))->assertOk()->assertDontSee('Onaya Gönder');

        $otherAdmin = User::factory()->create(['organization_id' => Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter'])->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherAdmin)->get(route('inventory.show', $count))->assertNotFound();
    }

    public function test_count_report_exports_to_excel_and_pdf(): void
    {
        Excel::fake();
        $this->stock(2, 10);
        $counts = app(StockCountService::class);
        $count = $counts->start($this->warehouse, $this->counter);
        $line = $count->lines()->orderBy('id')->first();
        $counts->record($count, [$line->id => ['counted_quantity' => 9, 'reason' => 'loss']], $this->counter);

        $this->actingAs($this->counter);
        Livewire::test('pages::inventory.show', ['count' => $count->id])->call('exportExcel');
        Excel::assertDownloaded('sayim-'.$count->number().'.xlsx', fn (TableExport $export) => $export->headings()[5] === 'Fark'
            && $export->collection()->contains(fn ($row) => $row[0] === 'Ürün 01' && $row[5] === -1.0 && $row[6] === 'Kayıp')
            && $export->collection()->contains(fn ($row) => $row[0] === 'Ürün 02' && $row[4] === null));

        Livewire::test('pages::inventory.show', ['count' => $count->id])->call('exportPdf')
            ->assertFileDownloaded('sayim-'.$count->number().'.pdf', contentType: 'application/pdf');
    }
}

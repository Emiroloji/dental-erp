<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Stock\Support\AlertMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 30 — toplu uyarı eşiği atama.
 *
 * Eşik ürün ürün girildiği için ürün sayısı arttıkça pratikte hiç girilmiyordu.
 * Toplu atama bir kategorinin (veya tüm kataloğun) aktif ürünlerine aynı eşiği
 * uygular; tek tek yapılmış istisnalar varsayılan olarak korunur.
 */
class BulkAlertThresholdTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Category $gloves;

    private Category $composites;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->gloves = Category::create(['organization_id' => $this->organization->id, 'name' => 'Eldivenler', 'status' => 'active']);
        $this->composites = Category::create(['organization_id' => $this->organization->id, 'name' => 'Kompozitler', 'status' => 'active']);

        $this->actingAs($this->admin);
    }

    private function product(string $name, ?Category $category, array $attributes = []): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'category_id' => $category?->id,
            'name' => $name,
            'base_unit' => 'Adet',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_the_threshold_is_applied_to_every_active_product_in_the_category(): void
    {
        $a = $this->product('Eldiven S', $this->gloves);
        $b = $this->product('Eldiven M', $this->gloves);
        $other = $this->product('Kompozit', $this->composites);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', AlertMode::Quantity->value)
            ->set('bulkQuantityLow', '60')
            ->set('bulkQuantityCritical', '30')
            ->call('applyBulkAlert')
            ->assertHasNoErrors()
            ->assertSee('2 ürüne uyarı eşiği uygulandı.');

        foreach ([$a, $b] as $product) {
            $product->refresh();
            $this->assertSame(AlertMode::Quantity, $product->alert_mode);
            $this->assertSame(60.0, (float) $product->alert_quantity_low);
            $this->assertSame(30.0, (float) $product->alert_quantity_critical);
        }

        $this->assertNull($other->refresh()->alert_mode, 'başka kategorideki ürüne dokunulmamalı');
    }

    public function test_products_with_their_own_threshold_are_left_alone_unless_overwrite_is_checked(): void
    {
        $plain = $this->product('Eldiven S', $this->gloves);
        $custom = $this->product('Eldiven M', $this->gloves, [
            'alert_mode' => AlertMode::Days->value,
            'alert_expiry_low_days' => 90,
            'alert_expiry_critical_days' => 45,
        ]);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', AlertMode::Quantity->value)
            ->set('bulkQuantityLow', '60')
            ->set('bulkQuantityCritical', '30')
            ->call('applyBulkAlert')
            ->assertSee('1 ürüne uyarı eşiği uygulandı.');

        $this->assertSame(AlertMode::Quantity, $plain->refresh()->alert_mode);
        $this->assertSame(AlertMode::Days, $custom->refresh()->alert_mode, 'elle yapılan istisna korunmalı');
        $this->assertSame(90, $custom->alert_expiry_low_days);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', AlertMode::Quantity->value)
            ->set('bulkQuantityLow', '60')
            ->set('bulkQuantityCritical', '30')
            ->set('bulkOverwrite', true)
            ->call('applyBulkAlert')
            ->assertSee('2 ürüne uyarı eşiği uygulandı.');

        $custom->refresh();
        $this->assertSame(AlertMode::Quantity, $custom->alert_mode);
        $this->assertNull($custom->alert_expiry_low_days, 'yeni modun kapsamadığı eksen temizlenmeli');
    }

    public function test_an_empty_category_means_the_whole_active_catalog(): void
    {
        $this->product('Eldiven S', $this->gloves);
        $this->product('Kompozit', $this->composites);
        $this->product('Kategorisiz', null);
        $this->product('Pasif Ürün', $this->gloves, ['status' => 'passive']);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkMode', AlertMode::Both->value)
            ->set('bulkQuantityLow', '20')
            ->set('bulkQuantityCritical', '5')
            ->set('bulkExpiryLowDays', '60')
            ->set('bulkExpiryCriticalDays', '30')
            ->call('applyBulkAlert')
            ->assertSee('3 ürüne uyarı eşiği uygulandı.');

        $this->assertSame(3, Product::whereNotNull('alert_mode')->count());
        $this->assertNull(Product::where('name', 'Pasif Ürün')->first()->alert_mode, 'pasif ürün kapsam dışı');

        // "İkisi birden" iki ekseni de yazar.
        $product = Product::where('name', 'Kompozit')->first();
        $this->assertSame(20.0, (float) $product->alert_quantity_low);
        $this->assertSame(60, $product->alert_expiry_low_days);
    }

    public function test_an_empty_mode_clears_the_custom_thresholds(): void
    {
        $custom = $this->product('Eldiven S', $this->gloves, [
            'alert_mode' => AlertMode::Quantity->value,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
        ]);
        $plain = $this->product('Eldiven M', $this->gloves);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', '')
            ->call('applyBulkAlert')
            ->assertHasNoErrors()
            ->assertSee('1 ürünün kendi eşiği temizlendi');

        $custom->refresh();
        $this->assertNull($custom->alert_mode);
        $this->assertNull($custom->alert_quantity_low);
        $this->assertFalse($custom->hasCustomAlertRule());
        $this->assertNull($plain->refresh()->alert_mode);
    }

    public function test_the_panel_shows_how_many_products_will_change_before_applying(): void
    {
        $this->product('Eldiven S', $this->gloves);
        $this->product('Eldiven M', $this->gloves);
        $this->product('Eldiven L', $this->gloves, ['alert_mode' => AlertMode::Quantity->value, 'alert_quantity_low' => 5, 'alert_quantity_critical' => 2]);

        $component = Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', AlertMode::Quantity->value);

        $component->assertSee('2 ürün');

        $component->set('bulkOverwrite', true)->assertSee('3 ürün');
    }

    public function test_the_red_threshold_must_be_stricter_than_the_yellow_one(): void
    {
        $this->product('Eldiven S', $this->gloves);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkMode', AlertMode::Quantity->value)
            ->set('bulkQuantityLow', '30')
            ->set('bulkQuantityCritical', '60')
            ->call('applyBulkAlert')
            ->assertHasErrors(['bulkQuantityCritical' => 'lt']);

        $this->assertNull(Product::where('name', 'Eldiven S')->first()->alert_mode);
    }

    public function test_the_thresholds_of_the_selected_mode_are_required(): void
    {
        $this->product('Eldiven S', $this->gloves);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkMode', AlertMode::Days->value)
            ->call('applyBulkAlert')
            ->assertHasErrors(['bulkExpiryLowDays' => 'required', 'bulkExpiryCriticalDays' => 'required']);
    }

    public function test_staff_without_write_access_cannot_open_the_bulk_panel(): void
    {
        $this->product('Eldiven S', $this->gloves);

        $staff = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ]);
        Permission::create([
            'user_id' => $staff->id,
            'module' => Module::ProductManagement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => 'all',
        ]);

        $this->actingAs($staff->fresh());

        Livewire::test('pages::catalog.products')
            ->assertDontSee('Toplu Eşik Ata')
            ->call('openBulkAlert')
            ->assertForbidden();
    }

    public function test_each_changed_product_gets_an_audit_log(): void
    {
        $this->product('Eldiven S', $this->gloves);
        $this->product('Eldiven M', $this->gloves);

        Livewire::test('pages::catalog.products')
            ->call('openBulkAlert')
            ->set('bulkCategoryId', (string) $this->gloves->id)
            ->set('bulkMode', AlertMode::Quantity->value)
            ->set('bulkQuantityLow', '60')
            ->set('bulkQuantityCritical', '30')
            ->call('applyBulkAlert')
            ->assertHasNoErrors();

        // Toplu bir UPDATE sorgusu yerine ürün ürün update edilmesinin nedeni:
        // her değişikliğin denetim izine düşmesi.
        $this->assertSame(2, AuditLog::where('entity_type', (new Product)->getMorphClass())
            ->where('action', 'updated')
            ->count());
    }
}

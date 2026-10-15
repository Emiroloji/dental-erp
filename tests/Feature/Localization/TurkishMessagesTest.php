<?php

namespace Tests\Feature\Localization;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Laravel'in hazır doğrulama ve sayfalama metinleri Türkçe gösterilir
 * (canlı QA raporu, 15.10.2026: formlarda "The name field is required.").
 */
class TurkishMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('tr');
    }

    public function test_form_shows_turkish_required_message(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez Şube', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Varsayılan Depo', 'is_default' => true, 'status' => 'active']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $component = Livewire::test('pages::organization.branches')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame('Ad alanı zorunludur.', $component->errors()->first('name'));
    }

    public function test_rule_messages_use_turkish_attribute_names(): void
    {
        $errors = Validator::make(
            ['quantity' => 'abc', 'lot_id' => '', 'statementDate' => '', 'description' => str_repeat('a', 501)],
            ['quantity' => 'numeric', 'lot_id' => 'required', 'statementDate' => 'required', 'description' => 'max:500'],
        )->errors();

        $this->assertSame('Miktar alanı sayı olmalıdır.', $errors->first('quantity'));
        $this->assertSame('Lot alanı zorunludur.', $errors->first('lot_id'));
        $this->assertSame('Ekstre tarihi alanı zorunludur.', $errors->first('statementDate'));
        $this->assertSame('Açıklama alanı en fazla 500 karakter olmalıdır.', $errors->first('description'));
    }

    public function test_every_framework_validation_rule_has_a_turkish_message(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $turkish = require lang_path('tr/validation.php');

        $keys = fn (array $lines) => array_keys(Arr::dot(Arr::except($lines, ['custom', 'attributes'])));

        $this->assertSame([], array_values(array_diff($keys($english), $keys($turkish))));
    }

    public function test_pagination_is_turkish(): void
    {
        $this->assertSame('Sonraki &raquo;', __('pagination.next'));
        $this->assertSame('&laquo; Önceki', __('pagination.previous'));
        $this->assertSame('sonuç', __('results'));
    }
}

<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsPendingScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        Product::create(['organization_id' => $org->id, 'name' => 'Implant X', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994']);
        $this->actingAs($this->admin);
    }

    private function fake(array $pending = [], ?UtsException $failure = null): void
    {
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient($pending, failure: $failure));
    }

    public function test_page_requires_login(): void
    {
        auth()->logout();

        $this->get('/uts/kabul-bekleyenler')->assertRedirect('/login');
    }

    public function test_admin_can_open_the_page_through_the_route(): void
    {
        $this->fake([]);

        $this->get('/uts/kabul-bekleyenler')->assertOk()->assertSee('Kabul Bekleyenler');
    }

    public function test_lists_pending_receipts_with_match_badges(): void
    {
        $this->fake([
            PendingReceipt::fromArray(['GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1', 'GKU' => 'Tedarik A.Ş.', 'MME' => 'Marka X']),
            PendingReceipt::fromArray(['GKK' => 7, 'UNO' => '09999999999991', 'LNO' => 'L9', 'ADT' => 1, 'BNO' => 'IRS-2', 'GKU' => 'Tedarik A.Ş.']),
        ]);

        Livewire::test('pages::uts.pending')
            ->assertSee('Tedarik A.Ş.')
            ->assertSee('IRS-1')
            ->assertSee('Ürün bizde var')
            ->assertSee('Ürün bizde yok');
    }

    public function test_shows_empty_state(): void
    {
        $this->fake([]);

        Livewire::test('pages::uts.pending')->assertSee('Kabul bekleyen bildirim yok');
    }

    public function test_shows_uts_error_instead_of_crashing(): void
    {
        $this->fake(failure: new UtsException('ÜTS şu an yanıt vermiyor.'));

        Livewire::test('pages::uts.pending')->assertSee('ÜTS şu an yanıt vermiyor.');
    }

    public function test_unconfigured_organization_is_pointed_to_settings(): void
    {
        Livewire::test('pages::uts.pending')->assertSee('ÜTS bağlantısı kurulmamış');
    }

    public function test_paging_moves_to_the_next_page(): void
    {
        $rows = array_map(fn ($n) => PendingReceipt::fromArray(['GKK' => 7, 'UNO' => "U{$n}", 'BNO' => "B{$n}-"]), range(1, 12));
        $this->fake($rows);

        Livewire::test('pages::uts.pending')
            ->assertSee('B1-')->assertDontSee('B11-')
            ->call('nextPage')->assertSee('B11-')->assertDontSee('B1-')
            ->call('previousPage')->assertSee('B1-');
    }

    public function test_sender_filter_passes_the_code_to_uts(): void
    {
        $this->fake([
            PendingReceipt::fromArray(['GKK' => 7, 'UNO' => 'U1', 'BNO' => 'A-7']),
            PendingReceipt::fromArray(['GKK' => 8, 'UNO' => 'U2', 'BNO' => 'A-8']),
        ]);

        Livewire::test('pages::uts.pending')->set('sender', '8')->assertSee('A-8')->assertDontSee('A-7');
    }
}

<?php

namespace Tests\Feature\Uts;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsReceiveService;
use App\Domain\Uts\Support\DeclinedReceipt;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 57: "Almak istemiyorum" işaretlenen bildirimleri listeleme (rev. 1.99,
 * 3.4.25) ve "Almak istiyorum" ile geri alma (3.4.23).
 */
class UtsDeclinedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->client = new FakeUtsClient(
            [PendingReceipt::fromArray(['BID' => 'vbi-1', 'GKK' => 7, 'GKU' => 'Tedarik A.Ş.', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 5, 'BNO' => 'IRS-1', 'MME' => 'Implant X'])],
            declinedRows: [DeclinedReceipt::fromArray(['BID' => 'vbi-9', 'GKK' => 8, 'DKU' => 'Başka Medikal', 'UNO' => '04049999999998', 'LNO' => 'BG-55', 'ADT' => 2, 'AID' => 'ALINMAK_ISTENMIYOR'])],
        );
        $this->app->instance(UtsClient::class, $this->client);
    }

    private function calls(string $method): array
    {
        return array_values(array_filter($this->client->calls, fn ($call) => $call[0] === $method));
    }

    public function test_http_client_queries_the_documented_endpoint_and_reads_the_list(): void
    {
        $rows = array_map(fn ($i) => ['BID' => "b-$i", 'GKK' => 7, 'KUN' => 10, 'DKU' => 'Erdil Medikal Ltd. Şti.', 'UNO' => '01111111110010', 'LNO' => '1', 'ADT' => 10, 'AID' => 'ALMAK_ISTEMIYORUM'], range(1, 20));
        Http::fake(['*' => Http::response(['SNC' => ['LST' => $rows, 'OFF' => 'sonraki-adres']])]);

        $page = (new HttpUtsClient('gizli', 'https://utstest.saglik.gov.tr', 5))->declinedReceipts('ilk-adres');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/almakIstemiyorum/sorgula/almakIstemedigimVermeBildirimlerim'
            && $r->data() === ['ADT' => 20, 'OFF' => 'ilk-adres']
            && $r->header('utsToken') === ['gizli']);
        $this->assertCount(20, $page->items);
        $this->assertSame('sonraki-adres', $page->nextOffset);
        $this->assertSame('Erdil Medikal Ltd. Şti.', $page->items[0]->institutionName);
        $this->assertSame(10, $page->items[0]->quantity);
        $this->assertTrue($page->items[0]->canUndo());
    }

    public function test_http_client_treats_a_short_page_as_the_last(): void
    {
        Http::fake(['*' => Http::response(['SNC' => ['LST' => [['BID' => 'b-1', 'UNO' => '1', 'AID' => 'ALMAK_ISTEMIYORUM']], 'OFF' => 'x']])]);

        $this->assertNull((new HttpUtsClient('gizli', 'https://utstest.saglik.gov.tr', 5))->declinedReceipts()->nextOffset);
    }

    public function test_http_client_undeclines_with_the_notification_code(): void
    {
        Http::fake(['*' => Http::response(['MSJ' => [['TIP' => 'BILGI', 'KOD' => 'UTS-B000', 'MET' => 'İşlem başarılı.']]])]);

        (new HttpUtsClient('gizli', 'https://utstest.saglik.gov.tr', 5))->undecline('vbi-1');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/almakIstemiyorum/almakIstiyorumOlarakIsaretle'
            && $r->data() === ['BID' => 'vbi-1']);
    }

    public function test_declined_row_can_be_taken_back_and_returns_to_pending(): void
    {
        Livewire::test('pages::uts.pending')->call('confirmDecline', 'vbi-1')->call('decline')->assertDontSee('IRS-1');

        Livewire::test('pages::uts.declined')
            ->assertSee('Tedarik A.Ş.')
            ->assertSee('L1')
            ->call('confirmUndo', 'vbi-1')
            ->assertSee('Bu adım ürünü kabul etmez')
            ->call('undo')
            ->assertSee('İşaret kaldırıldı');

        $this->assertSame([['undecline', ['vbi-1']]], $this->calls('undecline'));
        $record = UtsNotification::where('type', UtsNotification::TYPE_UNDECLINE)->sole();
        $this->assertSame('sent', $record->status);
        $this->assertSame('vbi-1', $record->source_notification_id);
        $this->assertSame('Almak istiyorum', $record->typeLabel());

        Livewire::test('pages::uts.pending')->assertSee('IRS-1');
    }

    public function test_row_declined_by_the_sender_cannot_be_taken_back(): void
    {
        Livewire::test('pages::uts.declined')
            ->assertSee('Başka Medikal')
            ->assertSee('Gönderen işaretledi')
            ->call('confirmUndo', 'vbi-9')
            ->call('undo')
            ->assertSee('buradan geri alınamaz');

        $this->assertSame([], $this->calls('undecline'));
        $this->assertSame(0, UtsNotification::count());
    }

    public function test_reader_sees_the_list_but_cannot_take_back(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->get(route('uts.declined'))->assertOk()->assertSee('Başka Medikal');
        Livewire::test('pages::uts.declined')->assertDontSee('Almak istiyorum</button>', false)->call('confirmUndo', 'vbi-9')->assertForbidden();
    }

    public function test_production_lock_blocks_the_write(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);
        $this->client->decline('vbi-1');

        Livewire::test('pages::uts.declined')
            ->call('confirmUndo', 'vbi-1')
            ->call('undo')
            ->assertSee('gerçek ortamda bildirim gönderimi henüz açılmadı');

        $this->assertSame([], $this->calls('undecline'));
    }

    public function test_failed_write_is_recorded(): void
    {
        $client = new FakeUtsClient(writeFailure: new UtsException('UTS-H999 hata'), declinedRows: [
            DeclinedReceipt::fromArray(['BID' => 'vbi-5', 'UNO' => '1', 'AID' => 'ALMAK_ISTEMIYORUM']),
        ]);
        $this->app->instance(UtsClient::class, $client);

        try {
            app(UtsReceiveService::class)->undecline($client->declinedReceipts()->items[0]);
            $this->fail('Hata beklenirdi.');
        } catch (UtsException) {
        }

        $this->assertSame('failed', UtsNotification::sole()->status);
    }

    public function test_uts_error_is_shown_instead_of_crashing(): void
    {
        $this->app->instance(UtsClient::class, new FakeUtsClient(failure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        Livewire::test('pages::uts.declined')->assertSee('ÜTS şu an yanıt vermiyor.');
    }

    public function test_every_uts_screen_has_the_new_tab(): void
    {
        foreach (['uts.pending', 'uts.verify', 'uts.history', 'uts.declined'] as $route) {
            $this->get(route($route))->assertOk()->assertSee(route('uts.declined'), false);
        }
    }
}

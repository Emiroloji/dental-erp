<?php

namespace Tests\Feature\Uts;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsReceiveScreenTest extends TestCase
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
        $this->useClient(new FakeUtsClient([
            PendingReceipt::fromArray(['BID' => 'vbi-1', 'GKK' => 7, 'GKU' => 'Tedarik A.Ş.', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 5, 'BNO' => 'IRS-1', 'MME' => 'Implant X']),
            PendingReceipt::fromArray(['BID' => 'vbi-2', 'GKK' => 7, 'GKU' => 'Tedarik A.Ş.', 'UNO' => '08699999999994', 'SNO' => 'S9', 'ADT' => 1, 'BNO' => 'IRS-2', 'MME' => 'Implant X']),
        ]));
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function staff(bool $write): User
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    private function receiveCalls(): array
    {
        return array_values(array_filter($this->client->calls, fn ($call) => $call[0] === 'receive'));
    }

    public function test_reader_sees_no_accept_action_and_cannot_call_it(): void
    {
        $this->actingAs($this->staff(write: false));

        Livewire::test('pages::uts.pending')
            ->assertDontSee('Kabul et')
            ->call('confirm', 'vbi-1')
            ->assertForbidden();
    }

    public function test_staff_with_stock_write_can_accept(): void
    {
        $this->actingAs($this->staff(write: true));

        Livewire::test('pages::uts.pending')
            ->assertSee('Kabul et')
            ->call('confirm', 'vbi-1')
            ->call('send');

        $this->assertSame([['receive', ['vbi-1', null]]], $this->receiveCalls());
    }

    public function test_single_row_action_keeps_the_checkbox_selection(): void
    {
        Livewire::test('pages::uts.pending')
            ->set('selected', ['vbi-2'])
            ->call('confirm', 'vbi-1')
            ->assertSet('selected', ['vbi-2'])
            ->assertSet('confirmRows.0.id', 'vbi-1');
    }

    public function test_confirmation_panel_warns_that_it_cannot_be_undone(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->assertSet('confirming', true)
            ->assertSee('geri alınamaz')
            ->assertSee('IRS-1');

        $this->assertSame([], $this->receiveCalls());
    }

    public function test_cancelling_the_panel_sends_nothing(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->call('closeConfirm')
            ->assertSet('confirming', false);

        $this->assertSame([], $this->receiveCalls());
    }

    public function test_single_accept_records_and_removes_the_row(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->call('send')
            ->assertSee('Kabul edildi')
            ->call('closeConfirm')
            ->assertDontSee('IRS-1')
            ->assertSee('IRS-2');

        $this->assertSame('sent', UtsNotification::sole()->status);
    }

    public function test_partial_quantity_is_sent_for_lot_rows(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->set('quantities.vbi-1', 3)
            ->call('send')
            ->assertSee('3 adet');

        $this->assertSame([['receive', ['vbi-1', 3]]], $this->receiveCalls());
    }

    public function test_bulk_accept_sends_every_selected_row(): void
    {
        Livewire::test('pages::uts.pending')
            ->set('selected', ['vbi-1', 'vbi-2'])
            ->call('confirm')
            ->assertSee('2 bildirim')
            ->call('send');

        $this->assertSame([['receive', ['vbi-1', null]], ['receive', ['vbi-2', null]]], $this->receiveCalls());
        $this->assertSame(2, UtsNotification::where('status', 'sent')->count());
    }

    public function test_uts_error_is_shown_per_row(): void
    {
        $this->useClient(new FakeUtsClient(
            [PendingReceipt::fromArray(['BID' => 'vbi-1', 'UNO' => 'U1', 'LNO' => 'L1', 'ADT' => 1, 'BNO' => 'IRS-1'])],
            writeFailure: new UtsException('Bildirim bulunamadı.'),
        ));

        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->call('send')
            ->assertSee('Bildirim bulunamadı.');
    }

    public function test_row_no_longer_pending_is_reported(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->set('confirmRows.0.id', 'vbi-yok')
            ->call('send')
            ->assertSee('artık kabul bekleyenler arasında değil');

        $this->assertSame([], $this->receiveCalls());
    }

    public function test_production_lock_message_is_shown(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);

        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->call('send')
            ->assertSee('gerçek ortamda bildirim gönderimi henüz açılmadı');

        $this->assertSame([], $this->receiveCalls());
    }

    public function test_read_only_organization_cannot_accept(): void
    {
        $this->org->update(['status' => 'read_only']);
        $this->actingAs($this->admin->fresh());

        Livewire::test('pages::uts.pending')
            ->assertDontSee('Kabul et')
            ->call('send')
            ->assertForbidden();
    }

    public function test_decline_removes_the_row_after_confirmation_and_is_recorded(): void
    {
        Livewire::test('pages::uts.pending')
            ->call('confirmDecline', 'vbi-1')
            ->assertSee('olarak işaretlenecek ve kabul bekleyenlerden kalkacak')
            ->call('decline')
            ->assertSee('olarak işaretlendi.')
            ->assertDontSee('IRS-1');

        $this->assertSame([['decline', ['vbi-1']]], array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'decline')));
        $record = UtsNotification::sole();
        $this->assertSame('almak_istemiyorum', $record->type);
        $this->assertSame('sent', $record->status);
        $this->assertSame('vbi-1', $record->source_notification_id);
    }

    public function test_decline_needs_stock_write(): void
    {
        $this->actingAs($this->staff(write: false));

        Livewire::test('pages::uts.pending')->call('confirmDecline', 'vbi-1')->assertForbidden();
    }

    public function test_decline_respects_the_production_lock(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);

        Livewire::test('pages::uts.pending')
            ->call('confirmDecline', 'vbi-1')
            ->call('decline')
            ->assertSee('gerçek ortamda bildirim gönderimi henüz açılmadı');

        $this->assertSame([], array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'decline')));
    }
}

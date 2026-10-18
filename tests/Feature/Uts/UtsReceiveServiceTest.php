<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsReceiveService;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtsReceiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function lotReceipt(int $quantity = 5): PendingReceipt
    {
        return PendingReceipt::fromArray(['BID' => 'vbi-lot', 'GKK' => 7, 'GKU' => 'Tedarik A.Ş.', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => $quantity, 'BNO' => 'IRS-1']);
    }

    private function serialReceipt(): PendingReceipt
    {
        return PendingReceipt::fromArray(['BID' => 'vbi-seri', 'GKK' => 7, 'UNO' => '08699999999994', 'SNO' => 'S1', 'ADT' => 1]);
    }

    private function service(): UtsReceiveService
    {
        return app(UtsReceiveService::class);
    }

    public function test_successful_receive_is_recorded(): void
    {
        $record = $this->service()->receive($this->lotReceipt());

        $this->assertSame([['receive', ['vbi-lot', null]]], $this->client->calls);
        $this->assertSame('sent', $record->status);
        $this->assertSame('sahte-alma-1', $record->uts_notification_id);
        $this->assertSame('vbi-lot', $record->source_notification_id);
        $this->assertSame($this->admin->id, $record->user_id);
        $this->assertSame('L1', $record->lot);
        $this->assertSame(5, $record->quantity);
        $this->assertSame('Tedarik A.Ş.', $record->sender_name);
        $this->assertSame('test', $record->environment);
    }

    public function test_partial_quantity_is_sent_for_lot_tracked_items(): void
    {
        $record = $this->service()->receive($this->lotReceipt(5), 3);

        $this->assertSame([['receive', ['vbi-lot', 3]]], $this->client->calls);
        $this->assertSame(3, $record->quantity);
    }

    public function test_full_quantity_is_sent_without_adt(): void
    {
        $this->service()->receive($this->lotReceipt(5), 5);

        $this->assertSame([['receive', ['vbi-lot', null]]], $this->client->calls);
    }

    public function test_quantity_must_be_between_one_and_the_given_amount(): void
    {
        foreach ([0, 6] as $quantity) {
            try {
                $this->service()->receive($this->lotReceipt(5), $quantity);
                $this->fail('Exception bekleniyordu.');
            } catch (UtsException $e) {
                $this->assertStringContainsString('1 ile 5', $e->getMessage());
            }
        }

        $this->assertSame([], $this->client->calls);
        $this->assertSame(0, UtsNotification::count());
    }

    public function test_serial_tracked_items_never_send_a_quantity(): void
    {
        $this->service()->receive($this->serialReceipt(), 1);

        $this->assertSame([['receive', ['vbi-seri', null]]], $this->client->calls);
    }

    public function test_failure_is_recorded_and_rethrown(): void
    {
        $this->useClient(new FakeUtsClient(failure: new UtsException('Bildirim bulunamadı.')));

        try {
            $this->service()->receive($this->lotReceipt());
            $this->fail('Exception bekleniyordu.');
        } catch (UtsException $e) {
            $this->assertSame('Bildirim bulunamadı.', $e->getMessage());
        }

        $record = UtsNotification::sole();
        $this->assertSame('failed', $record->status);
        $this->assertNull($record->uts_notification_id);
        $this->assertSame('Bildirim bulunamadı.', $record->message);
    }

    public function test_the_same_notification_is_not_received_twice(): void
    {
        $this->service()->receive($this->lotReceipt());

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('daha önce kabul edildi');

        $this->service()->receive($this->lotReceipt());
    }

    public function test_a_failed_attempt_can_be_retried(): void
    {
        $this->useClient(new FakeUtsClient(failure: new UtsException('ÜTS şu an yanıt vermiyor.')));
        rescue(fn () => $this->service()->receive($this->lotReceipt()), report: false);

        $this->useClient(new FakeUtsClient);
        $record = $this->service()->receive($this->lotReceipt());

        $this->assertSame('sent', $record->status);
    }

    public function test_production_writes_are_locked_by_default(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);

        try {
            $this->service()->receive($this->lotReceipt());
            $this->fail('Exception bekleniyordu.');
        } catch (UtsException $e) {
            $this->assertStringContainsString('gerçek ortamda', $e->getMessage());
        }

        $this->assertSame([], $this->client->calls);
        $this->assertSame(0, UtsNotification::count());
    }

    public function test_production_writes_can_be_unlocked(): void
    {
        config(['uts.production_writes' => true]);
        UtsConnection::first()->update(['environment' => 'production']);

        $record = $this->service()->receive($this->lotReceipt());

        $this->assertSame('production', $record->environment);
    }

    public function test_records_are_isolated_per_organization(): void
    {
        $this->service()->receive($this->lotReceipt());

        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        UtsConnection::create(['environment' => 'test', 'token' => 't']);

        $this->assertSame(0, UtsNotification::count());
        $this->assertSame('sent', $this->service()->receive($this->lotReceipt())->status);
    }
}

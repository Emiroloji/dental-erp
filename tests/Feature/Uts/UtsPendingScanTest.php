<?php

namespace Tests\Feature\Uts;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Jobs\ScanUtsPendingJob;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Services\UtsClientFactory;
use App\Domain\Uts\Services\UtsPendingScanService;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aşama 47 — ÜTS kabul bekleyenlerin arka planda taranması ve yeni gelenler
 * için bildirim. Tarama kuyrukta oturumsuz çalışır.
 */
class UtsPendingScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private UtsConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->connection = UtsConnection::create(['organization_id' => $this->org->id, 'environment' => 'test', 'token' => 't']);
    }

    private function useClient(UtsClient $client): void
    {
        $this->app->instance(UtsClientFactory::class, new class($client) extends UtsClientFactory
        {
            public function __construct(private readonly UtsClient $client) {}

            public function for(UtsConnection $connection): UtsClient
            {
                return $this->client;
            }
        });
    }

    private function row(string $bid, string $sender = 'Anadolu Dental'): PendingReceipt
    {
        return PendingReceipt::fromArray(['BID' => $bid, 'GKK' => 7, 'GKU' => $sender, 'UNO' => '08699999999994', 'ADT' => 1, 'BNO' => 'ADM-1']);
    }

    private function scan(): int
    {
        return app(UtsPendingScanService::class)->scan($this->connection);
    }

    public function test_new_pending_rows_notify_admin_and_stock_writers_once(): void
    {
        $writer = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $writer->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $reader = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $reader->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        $this->useClient(new FakeUtsClient([$this->row('v1'), $this->row('v2')]));

        $this->assertSame(2, $this->scan());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $writer->notifications()->count());
        $this->assertSame(0, $reader->notifications()->count());

        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('uts', $data['kind']);
        $this->assertStringContainsString('2 yeni', $data['message']);
        $this->assertStringContainsString('Anadolu Dental', $data['message']);
        $this->assertStringContainsString('/uts/kabul-bekleyenler', $data['url']);
    }

    public function test_already_seen_rows_do_not_notify_again(): void
    {
        $this->useClient(new FakeUtsClient([$this->row('v1')]));
        $this->scan();

        $this->assertSame(0, $this->scan());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_only_the_new_rows_are_counted_later(): void
    {
        $this->useClient(new FakeUtsClient([$this->row('v1')]));
        $this->scan();
        $this->admin->unreadNotifications->markAsRead();

        $this->useClient(new FakeUtsClient([$this->row('v1'), $this->row('v2', 'Ege Diş Deposu')]));

        $this->assertSame(1, $this->scan());
        $latest = $this->admin->unreadNotifications()->sole()->data;
        $this->assertStringContainsString('1 yeni', $latest['message']);
        $this->assertStringContainsString('Ege Diş Deposu', $latest['message']);
    }

    public function test_unread_notification_is_not_piled_up(): void
    {
        $this->useClient(new FakeUtsClient([$this->row('v1')]));
        $this->scan();
        $this->useClient(new FakeUtsClient([$this->row('v1'), $this->row('v2')]));

        $this->assertSame(1, $this->scan());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_uts_failure_is_reported_and_nothing_is_marked_seen(): void
    {
        $this->useClient(new FakeUtsClient(failure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        $this->expectException(UtsException::class);

        try {
            $this->scan();
        } finally {
            $this->assertSame(0, DB::table('uts_pending_seen')->count());
        }
    }

    public function test_command_queues_a_job_per_active_connection(): void
    {
        Queue::fake();
        $passive = Organization::create(['name' => 'Pasif', 'status' => 'passive', 'plan' => 'starter']);
        UtsConnection::create(['organization_id' => $passive->id, 'environment' => 'test', 'token' => 't']);

        $this->artisan('uts:scan-pending')->assertSuccessful();

        Queue::assertPushed(ScanUtsPendingJob::class, 1);
    }
}

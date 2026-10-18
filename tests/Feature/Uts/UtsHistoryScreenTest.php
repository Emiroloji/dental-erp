<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsHistoryScreenTest extends TestCase
{
    use RefreshDatabase;

    private function record(Organization $org, array $overrides = []): UtsNotification
    {
        return UtsNotification::create($overrides + [
            'organization_id' => $org->id, 'type' => 'alma', 'source_notification_id' => 'vbi-1',
            'status' => 'sent', 'uts_notification_id' => 'alma-kodu-1', 'environment' => 'test',
            'uno' => '08699999999994', 'lot' => 'L1', 'quantity' => 5, 'sender_name' => 'Tedarik A.Ş.',
        ]);
    }

    public function test_lists_sent_and_failed_notifications_of_own_organization_only(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->record($org);
        $this->record($org, ['source_notification_id' => 'vbi-2', 'status' => 'failed', 'uts_notification_id' => null, 'message' => 'Bildirim bulunamadı.']);
        $this->record($other, ['uts_notification_id' => 'baska-kurum']);

        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->get('/uts/gecmis')->assertOk()->assertSee('Gönderim Geçmişi');

        Livewire::test('pages::uts.history')
            ->assertSee('alma-kodu-1')
            ->assertSee('Gönderilemedi')
            ->assertSee('Bildirim bulunamadı.')
            ->assertDontSee('baska-kurum');
    }

    public function test_empty_state_points_to_pending_screen(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        Livewire::test('pages::uts.history')->assertSee('Henüz ÜTS\'ye bildirim gönderilmedi', false);
    }
}

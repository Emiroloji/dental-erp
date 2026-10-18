<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 42 kabul: test ortamı token'ı → bekleyeni eksik adetle kabul et →
 * gönderim geçmişinde gör; gerçek ortamda kilit gönderimi engeller.
 * Gerçek HttpUtsClient kullanılır; ÜTS Http::fake ile taklit edilir.
 */
class UtsReceiveAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_accepts_a_pending_notification_in_the_test_environment(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        Http::fake([
            'utstest.saglik.gov.tr/*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => [
                ['BID' => 'vbi-1', 'GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 10, 'BNO' => 'IRS-1', 'GKU' => 'Tedarik A.Ş.', 'MME' => 'Implant X'],
            ]]),
            'utstest.saglik.gov.tr/*/bildirim/alma/ekle' => Http::response([
                'SNC' => 'alma-kodu-1',
                'MSJ' => [['TIP' => 'BILGI', 'KOD' => 'UTS-B006', 'MET' => 'Alma bildirimi yapıldı.']],
            ]),
        ]);

        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'test')
            ->set('utsToken', 'tok')
            ->call('saveUts');

        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->assertSee('geri alınamaz')
            ->set('quantities.vbi-1', 8)
            ->call('send')
            ->assertSee('alma-kodu-1');

        Livewire::test('pages::uts.history')->assertSee('Implant X')->assertSee('alma-kodu-1');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/alma/ekle'
            && $r->data() === ['VBI' => 'vbi-1', 'ADT' => 8]
            && $r->header('utsToken') === ['tok']);
        $this->assertSame(8, UtsNotification::sole()->quantity);
    }

    public function test_production_connection_cannot_write_until_unlocked(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        Http::fake([
            'utsuygulama.saglik.gov.tr/*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => [
                ['BID' => 'vbi-1', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 1, 'BNO' => 'IRS-1'],
            ]]),
        ]);

        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'production')
            ->set('utsToken', 'tok')
            ->call('saveUts');

        Livewire::test('pages::uts.pending')
            ->call('confirm', 'vbi-1')
            ->call('send')
            ->assertSee('gerçek ortamda bildirim gönderimi henüz açılmadı');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/alma/ekle'));
        $this->assertSame(0, UtsNotification::count());
    }
}

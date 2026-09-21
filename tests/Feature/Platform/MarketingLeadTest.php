<?php

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\Lead;
use App\Domain\Platform\Support\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 29.2 — tanıtım sitesi ve talep formu. Doğrulama: girişsiz sayfalar
 * açılır, form bir Lead kaydı oluşturur (hesap/organizasyon açılmaz), bot
 * koruması çalışır ve talep yalnızca Platform Sahibi'nin panelinde görünür.
 */
class MarketingLeadTest extends TestCase
{
    use RefreshDatabase;

    private function filledForm(): Testable
    {
        return Livewire::test('pages::marketing.contact')
            ->set('name', 'Ayşe Yılmaz')
            ->set('clinic_name', 'Gülümseme Diş')
            ->set('phone', '0555 555 55 55')
            ->set('note', 'İki şubemiz var.');
    }

    public function test_marketing_pages_are_public(): void
    {
        foreach (['/', '/neler-yapiyor', '/talep'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_an_authenticated_user_is_sent_to_their_own_panel_from_the_marketing_home(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin)->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_form_creates_a_lead_without_opening_an_account(): void
    {
        $usersBefore = User::count();

        $this->filledForm()->call('submit')->assertHasNoErrors()->assertSet('sent', true);

        $lead = Lead::firstOrFail();

        $this->assertSame('Gülümseme Diş', $lead->clinic_name);
        $this->assertSame('Ayşe Yılmaz', $lead->name);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, Organization::count());
    }

    public function test_the_form_requires_a_name_a_clinic_and_one_way_to_reach_back(): void
    {
        Livewire::test('pages::marketing.contact')
            ->call('submit')
            ->assertHasErrors(['name', 'clinic_name', 'phone', 'email']);

        $this->assertSame(0, Lead::count());
    }

    public function test_an_email_alone_is_enough_to_reach_back(): void
    {
        Livewire::test('pages::marketing.contact')
            ->set('name', 'Mehmet Demir')
            ->set('clinic_name', 'Beyaz Diş')
            ->set('email', 'mehmet@example.com')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('mehmet@example.com', Lead::firstOrFail()->email);
    }

    public function test_a_filled_honeypot_field_silently_discards_the_submission(): void
    {
        $this->filledForm()
            ->set('website', 'https://spam.example')
            ->call('submit')
            ->assertSet('sent', true);

        $this->assertSame(0, Lead::count());
    }

    public function test_too_many_submissions_from_the_same_address_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->filledForm()->call('submit')->assertHasNoErrors();
        }

        $this->filledForm()->call('submit')->assertHasErrors('name');

        $this->assertSame(5, Lead::count());

        RateLimiter::clear('lead-form:'.request()->ip());
    }

    public function test_only_the_platform_owner_can_see_and_advance_incoming_leads(): void
    {
        $lead = Lead::create(['name' => 'Ayşe', 'clinic_name' => 'Gülümseme Diş', 'phone' => '0555', 'status' => LeadStatus::New]);

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);

        $this->actingAs($admin)->get('/platform/gelen-talepler')->assertForbidden();

        $this->actingAs($owner)->get('/platform/gelen-talepler')->assertOk()->assertSee('Gülümseme Diş');

        Livewire::test('pages::platform.leads')
            ->set('notes.'.$lead->id, 'Aradım, fiyat gönderildi.')
            ->call('markStatus', $lead->id, LeadStatus::Contacted->value);

        $lead->refresh();

        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame($owner->id, $lead->handled_by);
        $this->assertSame('Aradım, fiyat gönderildi.', $lead->internal_note);
    }
}

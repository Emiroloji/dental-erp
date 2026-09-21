<?php

use App\Domain\Platform\Services\LeadService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::marketing')] class extends Component
{
    public string $name = '';

    public string $clinic_name = '';

    public string $phone = '';

    public string $email = '';

    public string $note = '';

    /**
     * Honeypot: ekranda gizli, gerçek ziyaretçinin dolduramayacağı alan.
     * Doluysa kayıt oluşturulmaz — bot tarafına da belli edilmez.
     */
    public string $website = '';

    public bool $sent = false;

    public function mount(): void
    {
        if (auth()->check()) {
            $this->redirect('/dashboard');
        }
    }

    public function submit(LeadService $leads): void
    {
        if (filled($this->website)) {
            $this->sent = true;

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'clinic_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'phone.required_without' => 'Telefon veya e-postadan en az birini yazın.',
            'email.required_without' => 'Telefon veya e-postadan en az birini yazın.',
        ]);

        // Aynı IP'den kısa sürede çok sayıda form gönderimini sınırlar
        // (üçüncü parti CAPTCHA kullanılmıyor — Aşama 29.2).
        $key = 'lead-form:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('name', 'Çok sayıda talep gönderildi. Lütfen bir süre sonra tekrar deneyin.');

            return;
        }

        RateLimiter::hit($key, 3600);

        $leads->capture($validated, request()->ip());

        $this->reset(['name', 'clinic_name', 'phone', 'email', 'note']);
        $this->sent = true;
    }
};
?>

<div class="max-w-5xl mx-auto px-6 py-16">
    <div class="sm:flex sm:gap-16">
        <div class="sm:w-[42%]">
            <h1 class="text-[28px] font-medium tracking-tight leading-tight">Kliniğiniz için konuşalım</h1>
            <p class="mt-4 text-[15px] text-ink-muted leading-relaxed">
                Kaç şubeniz, kaç deponuz olduğunu ve bugün stoğu nasıl tuttuğunuzu yazın; size dönelim.
            </p>
            <p class="mt-4 text-[14px] text-ink-muted leading-relaxed">
                Bu form bir hesap açmaz. Talebinizi aldıktan sonra görüşür, uygun görürsek kliniğinizi
                ve yönetici hesabınızı biz oluşturup giriş bilgilerinizi göndeririz.
            </p>
        </div>

        <div class="sm:flex-1 mt-10 sm:mt-0">
            @if ($sent)
                <div class="border border-line rounded-lg bg-surface px-6 py-8">
                    <h2 class="text-[17px] font-medium">Talebiniz bize ulaştı</h2>
                    <p class="mt-2 text-[14px] text-ink-muted leading-relaxed">
                        En kısa sürede yazdığınız telefon veya e-postadan size döneceğiz.
                    </p>
                    <a href="{{ route('marketing.home') }}" class="mt-5 inline-block text-[14px] text-brand-600 hover:underline">Ana sayfaya dön</a>
                </div>
            @else
                <form wire:submit="submit" class="border border-line rounded-lg bg-surface px-6 py-6 space-y-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Adınız</label>
                        <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('name') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Klinik / hastane adı</label>
                        <input type="text" wire:model="clinic_name" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('clinic_name') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                            <input type="tel" wire:model="phone" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            @error('phone') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                            <input type="email" wire:model="email" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            @error('email') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-[12px] text-ink-muted -mt-1">Telefon veya e-postadan en az birini yazmanız yeterli.</p>

                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Not <span class="text-ink-muted/70">(isteğe bağlı)</span></label>
                        <textarea wire:model="note" rows="4" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500"></textarea>
                        @error('note') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    {{-- Honeypot: ekran okuyucudan ve kullanıcıdan gizli, yalnızca botlar doldurur. --}}
                    <div aria-hidden="true" class="absolute w-px h-px -m-px overflow-hidden" style="clip: rect(0 0 0 0)">
                        <label>Web sitesi<input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <button type="submit" class="w-full bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                        Talebi Gönder
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

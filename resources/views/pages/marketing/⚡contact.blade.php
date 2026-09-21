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

<div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-16 sm:py-20">
    <div class="lg:grid lg:grid-cols-12 lg:gap-16">
        <div class="lg:col-span-5">
            <h1 class="text-[32px] sm:text-[38px] leading-[1.1] tracking-[-0.03em] font-semibold max-w-[16ch]">
                Kliniğiniz için konuşalım
            </h1>
            <p class="mt-5 text-[16px] text-ink-muted leading-relaxed max-w-[52ch]">
                Kaç şubeniz ve deponuz olduğunu, bugün stoğu nasıl tuttuğunuzu yazın. Yazdığınız
                telefondan ya da e-postadan size dönelim.
            </p>

            <ol class="mt-10 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Formu gönderirsiniz', 'Kayıt bize talep olarak düşer. Bu adımda hesap açılmaz, ödeme istenmez.'],
                    ['Size döneriz', 'Kliniğinizin şube ve depo yapısını, hangi modülleri kullanacağınızı konuşuruz.'],
                    ['Hesabınızı biz kurarız', 'Uygun görürsek kliniğinizi ve yönetici hesabınızı açar, giriş bilgilerinizi göndeririz.'],
                ] as $i => [$title, $text])
                    <li class="py-5 flex gap-4">
                        <span class="shrink-0 w-6 h-6 rounded-full border border-line text-[13px] leading-[22px] text-center text-ink-muted tabular-nums">{{ $i + 1 }}</span>
                        <div>
                            <h2 class="text-[15px] font-medium">{{ $title }}</h2>
                            <p class="mt-1 text-[14px] text-ink-muted leading-relaxed max-w-[46ch]">{{ $text }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="lg:col-span-7 mt-12 lg:mt-0">
            @if ($sent)
                <div class="rounded-lg border border-brand-500/30 bg-brand-100/60 px-7 py-10">
                    <h2 class="text-[20px] font-semibold tracking-[-0.02em]">Talebiniz bize ulaştı</h2>
                    <p class="mt-3 text-[15px] text-ink-muted leading-relaxed max-w-[52ch]">
                        En kısa sürede yazdığınız telefon veya e-postadan size döneceğiz. Bu arada sistemin
                        neler yaptığına göz atabilirsiniz.
                    </p>
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <a href="{{ route('marketing.features') }}" class="bg-panel-900 text-white rounded-md px-5 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Neler yapıyor</a>
                        <a href="{{ route('marketing.home') }}" class="text-[14px] text-ink-muted hover:text-ink transition-colors">Ana sayfaya dön</a>
                    </div>
                </div>
            @else
                <form wire:submit="submit" class="rounded-lg border border-line bg-surface px-6 py-7 sm:px-8 sm:py-8 space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="lead-name" class="block text-[14px] mb-2">Adınız</label>
                            <input id="lead-name" type="text" wire:model="name" autocomplete="name"
                                class="w-full border border-line rounded-md px-3.5 py-2.5 text-[15px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            @error('name') <p class="text-status-critical text-[13px] mt-1.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="lead-clinic" class="block text-[14px] mb-2">Klinik / hastane adı</label>
                            <input id="lead-clinic" type="text" wire:model="clinic_name" autocomplete="organization"
                                class="w-full border border-line rounded-md px-3.5 py-2.5 text-[15px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            @error('clinic_name') <p class="text-status-critical text-[13px] mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <fieldset>
                        <legend class="text-[14px] mb-2">Size nasıl ulaşalım</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="lead-phone" class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                                <input id="lead-phone" type="tel" wire:model="phone" autocomplete="tel"
                                    class="w-full border border-line rounded-md px-3.5 py-2.5 text-[15px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('phone') <p class="text-status-critical text-[13px] mt-1.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="lead-email" class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                                <input id="lead-email" type="email" wire:model="email" autocomplete="email"
                                    class="w-full border border-line rounded-md px-3.5 py-2.5 text-[15px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('email') <p class="text-status-critical text-[13px] mt-1.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <p class="mt-2 text-[13px] text-ink-muted">İkisini birden yazmanız gerekmez, biri yeter.</p>
                    </fieldset>

                    <div>
                        <label for="lead-note" class="block text-[14px] mb-2">Kliniğiniz hakkında <span class="text-ink-muted">(isteğe bağlı)</span></label>
                        <textarea id="lead-note" wire:model="note" rows="5" placeholder="Örn. iki şube, üç depo; stoğu şu an Excel'de tutuyoruz."
                            class="w-full border border-line rounded-md px-3.5 py-2.5 text-[15px] leading-relaxed focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500"></textarea>
                        @error('note') <p class="text-status-critical text-[13px] mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    {{-- Honeypot: ekran okuyucudan ve kullanıcıdan gizli, yalnızca botlar doldurur. --}}
                    <div aria-hidden="true" class="absolute w-px h-px -m-px overflow-hidden" style="clip: rect(0 0 0 0)">
                        <label>Web sitesi<input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 pt-1">
                        <button type="submit" class="bg-panel-900 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-panel-800 transition-colors">
                            Talebi gönder
                        </button>
                        <span class="text-[13px] text-ink-muted">Gönderdiğinizde hesap açılmaz.</span>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

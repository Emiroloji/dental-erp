<?php

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceConnectionService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationSettingsService;
use App\Domain\Organization\Support\ExpiredLotPolicy;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsConnectionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $expiredLotPolicy = '';

    public string $utsEnvironment = 'test';

    public string $utsToken = '';

    public ?string $utsMessage = null;

    public ?string $utsError = null;

    public string $einvoiceProvider = 'nilvera';

    public string $einvoiceEnvironment = 'test';

    public string $einvoiceApiKey = '';

    public ?string $einvoiceMessage = null;

    public ?string $einvoiceError = null;

    public function mount(): void
    {
        $this->expiredLotPolicy = $this->organization()->expiredLotPolicy()->value;

        if ($einvoice = app(EInvoiceConnectionService::class)->current()) {
            $this->einvoiceProvider = $einvoice->provider;
            $this->einvoiceEnvironment = $einvoice->environment;
        }

        if ($connection = app(UtsConnectionService::class)->current()) {
            $this->utsEnvironment = $connection->environment;
        }
    }

    public function saveUts(UtsConnectionService $uts): void
    {
        Gate::authorize('uts_connection.update');

        $validated = $this->validate([
            'utsEnvironment' => ['required', Rule::in(['test', 'production'])],
            'utsToken' => ['required', 'string', 'max:2000'],
        ]);

        $uts->save($validated['utsEnvironment'], $validated['utsToken']);

        // Token geri gösterilmez; alan temizlenir.
        $this->reset('utsToken');
        $this->utsMessage = 'Token kaydedildi. "Bağlantıyı dene" ile doğrulayın.';
        $this->utsError = null;
    }

    public function testUts(UtsConnectionService $uts, UtsClient $client): void
    {
        Gate::authorize('uts_connection.update');

        $this->utsMessage = $this->utsError = null;

        try {
            $uts->test($client);
            $this->utsMessage = 'Bağlantı başarılı.';
        } catch (UtsException $e) {
            $this->utsError = $e->getMessage();
        }
    }

    public function removeUts(UtsConnectionService $uts): void
    {
        Gate::authorize('uts_connection.update');

        $uts->remove();
        $this->utsMessage = 'ÜTS bağlantısı kaldırıldı.';
        $this->utsError = null;
    }

    public function save(OrganizationSettingsService $settings): void
    {
        Gate::authorize('system_settings.update');

        $validated = $this->validate([
            'expiredLotPolicy' => ['required', Rule::enum(ExpiredLotPolicy::class)],
        ]);

        $settings->update($this->organization(), ExpiredLotPolicy::from($validated['expiredLotPolicy']));

        session()->flash('status', 'Ayarlar kaydedildi.');
    }

    public function saveEInvoice(EInvoiceConnectionService $einvoice): void
    {
        Gate::authorize('einvoice_connection.update');

        $validated = $this->validate([
            'einvoiceProvider' => ['required', Rule::in(array_keys(EInvoiceConnection::PROVIDERS))],
            'einvoiceEnvironment' => ['required', Rule::in(['test', 'production'])],
            'einvoiceApiKey' => ['required', 'string', 'max:4000'],
        ]);

        $einvoice->save($validated['einvoiceProvider'], $validated['einvoiceEnvironment'], $validated['einvoiceApiKey']);

        $this->reset('einvoiceApiKey');
        $this->einvoiceMessage = 'Anahtar kaydedildi. "Bağlantıyı dene" ile doğrulayın.';
        $this->einvoiceError = null;
    }

    public function testEInvoice(EInvoiceConnectionService $einvoice, EInvoiceProvider $provider): void
    {
        Gate::authorize('einvoice_connection.update');

        $this->einvoiceMessage = $this->einvoiceError = null;

        try {
            $einvoice->test($provider);
            $this->einvoiceMessage = 'Bağlantı başarılı.';
        } catch (EInvoiceException $e) {
            $this->einvoiceError = $e->getMessage();
        }
    }

    public function removeEInvoice(EInvoiceConnectionService $einvoice): void
    {
        Gate::authorize('einvoice_connection.update');

        $einvoice->remove();
        $this->einvoiceMessage = 'e-Fatura bağlantısı kaldırıldı.';
        $this->einvoiceError = null;
    }

    private function organization(): Organization
    {
        return Organization::findOrFail(auth()->user()->organization_id);
    }

    public function with(): array
    {
        return [
            'policies' => ExpiredLotPolicy::cases(),
            'utsConnection' => app(UtsConnectionService::class)->current(),
            'einvoiceConnection' => app(EInvoiceConnectionService::class)->current(),
            'einvoiceProviders' => EInvoiceConnection::PROVIDERS,
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Ayarlar</h1>
        <p class="text-[14px] text-ink-muted mt-1">Organizasyonunuzun tüm şube ve depolarında geçerli kurallar.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <section class="border border-line rounded-lg bg-surface p-5">
            <h2 class="text-[15px] font-medium text-ink">SKT'si geçmiş lotların kullanımı</h2>
            <p class="text-[13px] text-ink-muted mt-1 mb-4">Stok çıkışında son kullanma tarihi geçmiş bir lot kullanılmak istendiğinde sistemin davranışı.</p>

            <div class="space-y-3">
                @foreach ($policies as $policy)
                    <label @class([
                        'flex items-start gap-3 border rounded-md px-4 py-3',
                        'border-brand-500 bg-brand-100/40' => $expiredLotPolicy === $policy->value,
                        'border-line' => $expiredLotPolicy !== $policy->value,
                    ])>
                        <input type="radio" wire:model.live="expiredLotPolicy" value="{{ $policy->value }}" class="accent-brand-500 w-4 h-4 mt-0.5" @cannot('system_settings.update') disabled @endcannot>
                        <span>
                            <span class="block text-[14px] text-ink">{{ $policy->label() }}</span>
                            <span class="block text-[12px] text-ink-muted mt-0.5">{{ $policy->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('expiredLotPolicy') <span class="text-status-critical text-[12px] block mt-2">{{ $message }}</span> @enderror
        </section>

        @can('system_settings.update')
            <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                Ayarları Kaydet
            </button>
        @else
            <p class="text-[13px] text-ink-muted">Ayarları yalnızca görüntüleyebilirsiniz.</p>
        @endcan
    </form>

    <section class="max-w-2xl mt-8 border border-line rounded-lg bg-surface p-5">
        <h2 class="text-[15px] font-medium text-ink">ÜTS Bağlantısı</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Kabul bekleyen bildirimleri görmek ve lot/seri doğrulamak için kurumunuzun ÜTS sistem token'ı. Token'ı kurum yetkilisi ÜTS portalında e-imzayla üretir (Kullanıcı → Sistem Kullanıcısı Tanımlama İşlemleri). Bu token'la kabul (Alma) ve hastaya kullanım (Kullanım) bildirimleri de gönderilir; gerçek ortama gönderim sunucu ayarıyla açılana kadar kapalıdır.</p>

        @if ($utsMessage)
            <div class="mb-3 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ $utsMessage }}</div>
        @endif
        @if ($utsError)
            <div class="mb-3 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $utsError }}</div>
        @endif

        @if ($utsConnection)
            <p class="text-[13px] text-ink mb-3">
                Token kayıtlı ({{ $utsConnection->environment === 'production' ? 'Gerçek ortam' : 'Test ortamı' }})
                · {{ $utsConnection->last_verified_at ? 'son doğrulama: '.$utsConnection->last_verified_at->format('d.m.Y H:i') : 'henüz doğrulanmadı' }}
            </p>
        @endif

        @can('uts_connection.update')
            <form wire:submit="saveUts" class="space-y-3">
                <select wire:model="utsEnvironment" class="border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                    <option value="test">Test ortamı (utstest.saglik.gov.tr)</option>
                    <option value="production">Gerçek ortam (utsuygulama.saglik.gov.tr)</option>
                </select>
                @error('utsEnvironment') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <input type="password" wire:model="utsToken" autocomplete="off" placeholder="ÜTS sistem token'ı" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('utsToken') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Token'ı Kaydet</button>
                    @if ($utsConnection)
                        <button type="button" wire:click="testUts" class="border border-line rounded-md px-4 py-2 text-[14px] text-ink hover:bg-gray-50">Bağlantıyı dene</button>
                        <button type="button" wire:click="removeUts" wire:confirm="ÜTS bağlantısı kaldırılsın mı?" class="border border-line rounded-md px-4 py-2 text-[14px] text-status-critical hover:bg-red-50">Kaldır</button>
                    @endif
                </div>
            </form>
        @endcan
    </section>

    <section class="max-w-2xl mt-8 border border-line rounded-lg bg-surface p-5">
        <h2 class="text-[15px] font-medium text-ink">e-Fatura Bağlantısı</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Tedarikçilerin kestiği e-faturaları entegratörünüzden her 30 dakikada çekmek için API anahtarı. Anahtarı entegratörün portalında üretin; yalnızca gelen fatura okuma yetkisi yeterlidir.</p>

        @if ($einvoiceMessage)
            <div class="mb-3 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ $einvoiceMessage }}</div>
        @endif
        @if ($einvoiceError)
            <div class="mb-3 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $einvoiceError }}</div>
        @endif

        @if ($einvoiceConnection)
            <p class="text-[13px] text-ink mb-3">
                Anahtar kayıtlı: {{ $einvoiceProviders[$einvoiceConnection->provider] ?? $einvoiceConnection->provider }}, {{ $einvoiceConnection->environment === 'production' ? 'gerçek ortam' : 'test ortamı' }}.
                {{ $einvoiceConnection->last_verified_at ? 'Son doğrulama '.$einvoiceConnection->last_verified_at->format('d.m.Y H:i').'.' : 'Henüz doğrulanmadı.' }}
                @if ($einvoiceConnection->last_synced_at) Son çekim {{ $einvoiceConnection->last_synced_at->format('d.m.Y H:i') }}. @endif
            </p>
        @endif

        @can('einvoice_connection.update')
            <form wire:submit="saveEInvoice" class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <select wire:model="einvoiceProvider" aria-label="Entegratör" class="border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        @foreach ($einvoiceProviders as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:model="einvoiceEnvironment" aria-label="Ortam" class="border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="test">Test ortamı</option>
                        <option value="production">Gerçek ortam</option>
                    </select>
                </div>
                @error('einvoiceProvider') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                @error('einvoiceEnvironment') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <input type="password" wire:model="einvoiceApiKey" autocomplete="off" placeholder="Entegratör API anahtarı" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('einvoiceApiKey') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Anahtarı Kaydet</button>
                    @if ($einvoiceConnection)
                        <button type="button" wire:click="testEInvoice" class="border border-line rounded-md px-4 py-2 text-[14px] text-ink hover:bg-gray-50">Bağlantıyı dene</button>
                        <button type="button" wire:click="removeEInvoice" wire:confirm="e-Fatura bağlantısı kaldırılsın mı? Çekilmiş faturalar silinmez." class="border border-line rounded-md px-4 py-2 text-[14px] text-status-critical hover:bg-red-50">Kaldır</button>
                    @endif
                </div>
            </form>
        @endcan
    </section>
</div>

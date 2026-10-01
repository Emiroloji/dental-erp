<?php

use App\Domain\Catalog\Support\Gs1;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bir lot/seri numarasını ÜTS'de sorgular ve yerel kayıtla karşılaştırır
 * (Aşama 33, salt okunur). GS1 DataMatrix okutulursa alanlar dolar.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public string $scan = '';

    public string $uno = '';

    public string $lot = '';

    public string $serial = '';

    /** @var array<int, array<string, mixed>>|null */
    public ?array $results = null;

    public ?string $error = null;

    public function updatedScan(): void
    {
        if ($gs1 = Gs1::parse($this->scan)) {
            $this->uno = $gs1['gtin'];
            $this->lot = (string) ($gs1['lot_no'] ?? '');
            $this->serial = (string) ($gs1['serial'] ?? '');
            $this->scan = '';
            $this->check();
        }
    }

    public function check(): void
    {
        $this->validate([
            'uno' => ['required', 'string', 'max:23'],
            'lot' => ['nullable', 'string', 'max:36'],
            'serial' => ['nullable', 'string', 'max:36'],
        ]);

        $this->error = null;
        $this->results = null;

        try {
            $items = app(UtsClient::class)->lookup(trim($this->uno), trim($this->lot) ?: null, trim($this->serial) ?: null);
        } catch (UtsException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $matcher = app(UtsMatcher::class);

        $this->results = array_map(function ($item) use ($matcher) {
            $match = $matcher->match($item->uno, $item->lot, $item->serial);
            $localExpiry = $match['lot']?->expiry_date?->toDateString();
            $utsExpiry = $this->normalizeDate($item->expiryDate);

            return [
                'uno' => $item->uno,
                'lot' => $item->lot,
                'serial' => $item->serial,
                'quantity' => $item->quantity,
                'brand' => $item->brand,
                'type' => $item->productType,
                'tracking' => $item->tracking,
                'udi' => $item->udi,
                'expiry' => $utsExpiry ? Carbon::parse($utsExpiry)->format('d.m.Y') : null,
                'product' => $match['product']?->name,
                'lotKnown' => $match['lot'] !== null,
                'serialKnown' => $match['serial'] !== null,
                'expiryMatches' => $localExpiry && $utsExpiry ? $localExpiry === $utsExpiry : null,
            ];
        }, $items);
    }

    /**
     * ÜTS tarihleri "2028-01-31" ya da "31.01.2028" gelebilir; Y-m-d'ye çevrilir.
     */
    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Lot/Seri Doğrula</h1>
        <p class="text-[14px] text-ink-muted mt-1">Bir ürünün lot veya seri numarasını ÜTS'de sorgulayın ve kendi kayıtlarınızla karşılaştırın. Kutudaki kare kodu okutabilirsiniz.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="text-ink-muted hover:text-ink pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Lot/Seri Doğrula</a>
        </nav>
    </div>

    <form wire:submit="check" class="max-w-2xl space-y-3 mb-6">
        <input type="text" wire:model.live.debounce.300ms="scan" autofocus placeholder="Kare kodu okutun (GS1 DataMatrix)" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
        <div class="grid sm:grid-cols-3 gap-3">
            <div>
                <input type="text" wire:model="uno" placeholder="Ürün no / GTIN" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('uno') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <input type="text" wire:model="lot" placeholder="Lot no" class="border border-line rounded-md px-3 py-2 text-[14px]">
            <input type="text" wire:model="serial" placeholder="Seri no" class="border border-line rounded-md px-3 py-2 text-[14px]">
        </div>
        <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">ÜTS'de Sorgula</button>
    </form>

    @if ($error)
        <div class="rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3 max-w-2xl">{{ $error }}</div>
    @elseif ($results !== null)
        @forelse ($results as $result)
            <div class="border border-line rounded-lg bg-surface p-5 max-w-2xl mb-3">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">ÜTS'de kayıtlı</span>
                    @if ($result['product'])
                        <span class="text-[14px] text-ink">{{ $result['product'] }}</span>
                    @else
                        <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[12px]">Ürün bizde yok</span>
                    @endif
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-[13px]">
                    <dt class="text-ink-muted">Marka/model</dt><dd>{{ $result['brand'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">Ürün no</dt><dd>{{ $result['uno'] }}</dd>
                    <dt class="text-ink-muted">Lot / Seri</dt><dd>{{ $result['lot'] ?? '—' }} {{ $result['serial'] ? '/ '.$result['serial'] : '' }}</dd>
                    <dt class="text-ink-muted">Adet</dt><dd>{{ $result['quantity'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">Takip tipi</dt><dd>{{ $result['tracking'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">SKT (ÜTS)</dt><dd>{{ $result['expiry'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">UDI</dt><dd class="break-all">{{ $result['udi'] ?? '—' }}</dd>
                </dl>
                <div class="flex flex-wrap gap-1 mt-3">
                    @if ($result['lotKnown']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Lot bizde kayıtlı</span> @endif
                    @if ($result['serialKnown']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Seri bizde kayıtlı</span> @endif
                    @if ($result['expiryMatches'] === true) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">SKT uyuşuyor</span> @endif
                    @if ($result['expiryMatches'] === false) <span class="rounded-full bg-red-100 text-status-critical px-2 py-0.5 text-[12px]">SKT uyuşmuyor</span> @endif
                </div>
            </div>
        @empty
            <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted max-w-2xl">ÜTS'de kayıt bulunamadı. Numaraları kontrol edin; ürün kurumunuza henüz verilmemiş olabilir.</div>
        @endforelse
    @endif
</div>

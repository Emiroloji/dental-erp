<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationSettingsService;
use App\Domain\Organization\Support\ExpiredLotPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $expiredLotPolicy = '';

    public function mount(): void
    {
        $this->expiredLotPolicy = $this->organization()->expiredLotPolicy()->value;
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

    private function organization(): Organization
    {
        return Organization::findOrFail(auth()->user()->organization_id);
    }

    public function with(): array
    {
        return [
            'policies' => ExpiredLotPolicy::cases(),
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
</div>

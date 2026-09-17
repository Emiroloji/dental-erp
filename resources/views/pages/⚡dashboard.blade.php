<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public function with(): array
    {
        return [
            'productCount' => Product::where('status', 'active')->count(),
            'categoryCount' => Category::where('status', 'active')->count(),
            'supplierCount' => Supplier::where('status', 'active')->count(),
            'staffCount' => User::where('organization_id', auth()->user()->organization_id)
                ->where('role', User::ROLE_STAFF)
                ->count(),
            'branchCount' => Branch::where('status', 'active')->count(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <p class="text-[13px] text-ink-muted mb-1">{{ now()->translatedFormat('d F Y, l') }}</p>
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Kontrol Paneli</h1>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line">
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Aktif Ürün</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $productCount }}</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Kategori</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $categoryCount }}</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Tedarikçi</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $supplierCount }}</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Personel</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $staffCount }}</p>
        </div>
    </div>

    <div class="mt-10 border border-line rounded-lg bg-surface px-6 py-10 text-center">
        <p class="text-[14px] text-ink-muted max-w-sm mx-auto">
            Stok hareketleri, kritik seviye uyarıları ve şube bazlı özet raporlar bir sonraki aşamada bu panele eklenecek.
        </p>
    </div>
</div>

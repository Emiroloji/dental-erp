<?php

use App\Domain\Uts\Models\UtsNotification;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * ÜTS'ye gönderilen bildirimlerin kaydı (Aşama 42): başarılı ve başarısız
 * her Alma denemesi, kim ve ne zaman gönderdiğiyle. Salt okunur.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public function with(): array
    {
        return [
            'notifications' => UtsNotification::with('user:id,name')->latest('id')->paginate(20),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Gönderim Geçmişi</h1>
        <p class="text-[14px] text-ink-muted mt-1">Bu uygulamadan ÜTS'ye gönderilen Alma bildirimleri. Başarısız denemeler de ÜTS'nin verdiği mesajla birlikte burada kalır.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="text-ink-muted hover:text-ink pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="text-ink-muted hover:text-ink pb-1">Lot/Seri Doğrula</a>
            <a href="{{ route('uts.history') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Gönderim Geçmişi</a>
        </nav>
    </div>

    @if ($notifications->isEmpty())
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted">
            Henüz ÜTS'ye bildirim gönderilmedi. Kabul etmek için <a href="{{ route('uts.pending') }}" class="underline text-ink">Kabul Bekleyenler</a> ekranını açın.
        </div>
    @else
        <div class="overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-3 font-medium">Zaman</th>
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium">Gönderen</th>
                        <th class="px-4 py-3 font-medium">Kullanıcı</th>
                        <th class="px-4 py-3 font-medium">Sonuç</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notifications as $n)
                        <tr wire:key="n-{{ $n->id }}" class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ $n->created_at->format('d.m.Y H:i') }}
                                @if ($n->environment === 'test') <div class="text-ink-muted">Test ortamı</div> @endif
                            </td>
                            <td class="px-4 py-3">@if ($n->product_name)<div class="text-ink">{{ $n->product_name }}</div>@endif<div class="{{ $n->product_name ? 'text-ink-muted' : '' }}">{{ $n->uno }}</div>@if ($n->document_no) <div class="text-ink-muted">Belge {{ $n->document_no }}</div>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $n->lot ?? '—' }}@if ($n->serial) <div class="text-ink-muted">{{ $n->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $n->quantity ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $n->sender_name ?? '—' }}@if ($n->sender_code) <div class="text-ink-muted">{{ $n->sender_code }}</div>@endif</td>
                            <td class="px-4 py-3">{{ $n->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($n->status === 'sent')
                                    <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Kabul edildi</span>
                                    <div class="text-ink-muted mt-1 break-all">{{ $n->uts_notification_id }}</div>
                                @else
                                    <span class="rounded-full bg-red-50 text-status-critical px-2 py-0.5 text-[12px]">Gönderilemedi</span>
                                    <div class="text-ink-muted mt-1">{{ $n->message }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
</div>

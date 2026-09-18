<?php

use App\Domain\Stock\Notifications\StockLevelAlert;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $kindFilter = '';

    /** Bildirim türleri: stok uyarıları + iş akışı bildirimleri (WorkflowNotification::kind). */
    public const KINDS = [
        'stock' => 'Stok Uyarıları',
        'transfer' => 'Transfer',
        'purchase' => 'Satın Alma',
        'stock_count' => 'Stok Sayımı',
        'return' => 'İade',
        'plan' => 'Paket',
    ];

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingKindFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Bildirimi okundu işaretler ve ilgili sayfaya yönlendirir (proje.md Bölüm 11).
     */
    public function open(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->first();

        abort_unless($notification, 404);

        $notification->markAsRead();

        if (filled($notification->data['url'] ?? null)) {
            $this->redirect($notification->data['url']);
        }
    }

    public function markAsRead(string $notificationId): void
    {
        try {
            $notification = auth()->user()->notifications()->whereKey($notificationId)->firstOrFail();
        } catch (ModelNotFoundException) {
            // Livewire's test harness doesn't convert ModelNotFoundException into a 404
            // response the way a real HTTP request does, so we convert it explicitly —
            // this also keeps error handling consistent with the stock movements screen.
            abort(404);
        }

        $notification->markAsRead();
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function with(): array
    {
        $query = auth()->user()->notifications();

        if ($this->kindFilter === 'stock') {
            $query->where('type', StockLevelAlert::class);
        } elseif (array_key_exists($this->kindFilter, self::KINDS)) {
            $query->where('data->kind', $this->kindFilter);
        }

        if ($this->statusFilter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->statusFilter === 'read') {
            $query->whereNotNull('read_at');
        }

        return [
            'notifications' => $query->latest()->paginate(15),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
            'kinds' => self::KINDS,
        ];
    }
};
?>

<div>
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Bildirimler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Stok uyarıları ile transfer, satın alma, sayım ve iade bildirimlerini görüntüle.</p>
        </div>
        @if ($unreadCount > 0)
            <button wire:click="markAllAsRead" class="text-[13px] text-brand-600 hover:underline shrink-0">Tümünü okundu işaretle</button>
        @endif
    </div>

    <div class="mb-4 flex gap-3">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tümü</option>
            <option value="unread">Okunmamış</option>
            <option value="read">Okunmuş</option>
        </select>
        <select wire:model.live="kindFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Türler</option>
            @foreach ($kinds as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <div class="divide-y divide-line">
            @forelse ($notifications as $notification)
                <div wire:key="notification-{{ $notification->id }}" class="px-5 py-4 flex items-start justify-between gap-4 {{ $notification->read_at ? '' : 'bg-brand-100/40' }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ match ($notification->data['level'] ?? null) {
                                'critical' => 'bg-status-critical-bg text-status-critical',
                                'good' => 'bg-status-good-bg text-status-good',
                                'info' => 'bg-brand-100 text-brand-600',
                                default => 'bg-status-warn-bg text-status-warn',
                            } }}">
                                {{ $notification->data['title'] ?? 'Bildirim' }}
                            </span>
                            @unless ($notification->read_at)
                                <span class="w-1.5 h-1.5 rounded-full bg-brand-500"></span>
                            @endunless
                        </div>
                        <p class="text-[14px] text-ink">{{ $notification->data['message'] ?? $notification->data['product_name'] ?? '—' }}</p>
                        @if (! empty($notification->data['reasons']))
                            <p class="text-[13px] text-ink-muted mt-0.5">{{ implode(' · ', $notification->data['reasons']) }}</p>
                        @endif
                        <p class="text-[12px] text-ink-muted mt-1">{{ $notification->created_at->format('d.m.Y H:i') }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        @if (filled($notification->data['url'] ?? null))
                            <button wire:click="open('{{ $notification->id }}')" class="text-[13px] text-brand-600 hover:underline">Görüntüle</button>
                        @endif
                        @unless ($notification->read_at)
                            <button wire:click="markAsRead('{{ $notification->id }}')" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Okundu işaretle</button>
                        @endunless
                    </div>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-ink-muted text-[13px]">Bildirim bulunamadı.</div>
            @endforelse
        </div>

        @if ($notifications->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $notifications->links() }}
            </div>
        @endif
    </section>
</div>

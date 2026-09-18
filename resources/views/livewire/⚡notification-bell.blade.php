<?php

use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Okundu işaretler; bildirim bir sayfaya bağlıysa oraya yönlendirir.
     */
    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->first();

        $notification?->markAsRead();

        if (filled($notification?->data['url'] ?? null)) {
            $this->redirect($notification->data['url']);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'unreadCount' => $user->unreadNotifications()->count(),
            'recent' => $user->notifications()->latest()->limit(8)->get(),
        ];
    }
};
?>

<div class="relative" x-data="{}" @click.outside="$wire.open = false">
    <button wire:click="toggle" type="button" class="relative text-white/60 hover:text-white/90 transition-colors" title="Bildirimler">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        @if ($unreadCount > 0)
            <span class="absolute -top-1.5 -right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-status-critical text-white text-[10px] leading-4 text-center font-medium">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="absolute right-0 bottom-full mb-2 lg:bottom-auto lg:top-full lg:mt-2 w-80 max-w-[calc(100vw-2rem)] bg-surface text-ink border border-line rounded-lg shadow-lg z-50 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-line">
                <span class="text-[13px] font-medium">Bildirimler</span>
                @if ($unreadCount > 0)
                    <button wire:click="markAllAsRead" type="button" class="text-[12px] text-brand-600 hover:underline">Tümünü okundu işaretle</button>
                @endif
            </div>

            <div class="max-h-80 overflow-y-auto divide-y divide-line">
                @forelse ($recent as $notification)
                    <button
                        wire:click="markAsRead('{{ $notification->id }}')"
                        wire:key="bell-notification-{{ $notification->id }}"
                        type="button"
                        class="w-full text-left px-4 py-3 text-[13px] hover:bg-canvas transition-colors {{ $notification->read_at ? 'opacity-60' : '' }}"
                    >
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="inline-block w-1.5 h-1.5 rounded-full {{ match ($notification->data['level'] ?? null) {
                                'critical' => 'bg-status-critical',
                                'good' => 'bg-status-good',
                                'info' => 'bg-brand-500',
                                default => 'bg-status-warn',
                            } }}"></span>
                            <span class="font-medium">{{ $notification->data['title'] ?? 'Bildirim' }}</span>
                        </div>
                        <p class="text-ink-muted">{{ $notification->data['message'] ?? $notification->data['product_name'] ?? '' }}</p>
                        <p class="text-ink-muted text-[11px] mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                    </button>
                @empty
                    <p class="px-4 py-6 text-center text-[13px] text-ink-muted">Bildirim yok.</p>
                @endforelse
            </div>

            <a href="{{ route('notifications.index') }}" wire:navigate class="block px-4 py-3 text-center text-[13px] text-brand-600 border-t border-line hover:bg-canvas transition-colors">
                Tüm bildirimleri gör
            </a>
        </div>
    @endif
</div>

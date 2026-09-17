@props(['show' => false, 'title' => null, 'onClose' => null])

@if ($show)
    <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 sm:p-6 overflow-y-auto">
        <div
            class="fixed inset-0 bg-panel-900/50"
            @if ($onClose) wire:click="{{ $onClose }}" @endif
        ></div>

        <div class="relative bg-surface rounded-lg border border-line w-full max-w-2xl shadow-xl my-8 sm:my-0">
            <div class="flex items-center justify-between px-6 py-4 border-b border-line">
                <h2 class="text-[15px] font-medium text-ink">{{ $title }}</h2>
                @if ($onClose)
                    <button type="button" wire:click="{{ $onClose }}" class="text-ink-muted hover:text-ink">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                @endif
            </div>

            <div class="p-6">
                {{ $slot }}
            </div>
        </div>
    </div>
@endif

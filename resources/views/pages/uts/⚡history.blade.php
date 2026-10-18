<?php

use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsUsageService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * ÜTS'ye gönderilen bildirimlerin kaydı: Alma (Aşama 42), Kullanım ve
 * Kullanım iptali (Aşama 43); kim ve ne zaman gönderdiğiyle. Stok yazma
 * yetkisi olan, gönderilemeyen kullanımı tekrar gönderir ve bildirilmiş
 * kullanımı onay panelinden geçirerek ÜTS'de iptal eder. Hasta adı yalnızca
 * hasta okuma yetkisiyle görünür.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public ?int $cancellingId = null;

    public function retry(int $notificationId, UtsUsageService $usages): void
    {
        Gate::authorize('stock_movement.create');

        $usage = $this->usageOf($notificationId);

        try {
            $usages->report($usage);
            session()->flash('status', 'Kullanım ÜTS\'ye bildirildi.');
        } catch (UtsException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function confirmCancel(int $notificationId): void
    {
        Gate::authorize('stock_movement.create');

        $this->usageOf($notificationId);
        $this->cancellingId = $notificationId;
    }

    public function closeCancel(): void
    {
        $this->cancellingId = null;
    }

    public function cancelUsage(UtsUsageService $usages): void
    {
        Gate::authorize('stock_movement.create');

        $usage = $this->usageOf((int) $this->cancellingId);
        $this->cancellingId = null;

        try {
            $usages->cancel($usage);
            session()->flash('status', 'Kullanım bildirimi ÜTS\'de iptal edildi. Stok çıkışını artık Stok Hareketleri\'nden iptal edebilirsiniz.');
        } catch (UtsException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function usageOf(int $notificationId): PatientUsage
    {
        $notification = UtsNotification::whereNotNull('patient_usage_id')->findOrFail($notificationId);

        return PatientUsage::with(['patient', 'product', 'lot', 'serial', 'utsNotifications'])->findOrFail($notification->patient_usage_id);
    }

    public function with(): array
    {
        $cancelling = $this->cancellingId ? UtsNotification::with('patientUsage.patient', 'patientUsage.product')->find($this->cancellingId) : null;

        return [
            'notifications' => UtsNotification::with(['user:id,name', 'patientUsage.patient', 'patientUsage.utsNotifications'])->latest('id')->paginate(20),
            'canAct' => Gate::allows('stock_movement.create'),
            'canSeePatients' => Gate::allows('patients.viewAny'),
            'cancelling' => $cancelling,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Gönderim Geçmişi</h1>
        <p class="text-[14px] text-ink-muted mt-1">Bu uygulamadan ÜTS'ye gönderilen Alma ve Kullanım bildirimleri. Başarısız denemeler de ÜTS'nin verdiği mesajla birlikte burada kalır.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="text-ink-muted hover:text-ink pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="text-ink-muted hover:text-ink pb-1">Lot/Seri Doğrula</a>
            <a href="{{ route('uts.history') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Gönderim Geçmişi</a>
        </nav>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    @if ($notifications->isEmpty())
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted">
            Henüz ÜTS'ye bildirim gönderilmedi. Kabul etmek için <a href="{{ route('uts.pending') }}" class="underline text-ink">Kabul Bekleyenler</a> ekranını açın.
        </div>
    @else
        <div class="relative overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-3 font-medium">Zaman</th>
                        <th class="px-4 py-3 font-medium">Tür</th>
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium">Gönderen / Hasta</th>
                        <th class="px-4 py-3 font-medium">Kullanıcı</th>
                        <th class="px-4 py-3 font-medium">Sonuç</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notifications as $n)
                        @php($usage = $n->patientUsage)
                        <tr wire:key="n-{{ $n->id }}" class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ $n->created_at->format('d.m.Y H:i') }}
                                @if ($n->environment === 'test') <div class="text-ink-muted">Test ortamı</div> @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $n->typeLabel() }}</td>
                            <td class="px-4 py-3">@if ($n->product_name)<div class="text-ink">{{ $n->product_name }}</div>@endif<div class="{{ $n->product_name ? 'text-ink-muted' : '' }}">{{ $n->uno }}</div>@if ($n->document_no) <div class="text-ink-muted">Belge {{ $n->document_no }}</div>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $n->lot ?? '—' }}@if ($n->serial) <div class="text-ink-muted">{{ $n->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $n->quantity ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($usage)
                                    @if ($canSeePatients)
                                        <a href="{{ route('patients.show', $usage->patient_id) }}" class="text-ink hover:underline">{{ $usage->patient->fullName() }}</a>
                                        <div class="text-ink-muted">{{ $usage->patient->maskedIdentity() }}</div>
                                    @else
                                        <span class="text-ink-muted">Hasta kaydı</span>
                                    @endif
                                @else
                                    {{ $n->sender_name ?? '—' }}@if ($n->sender_code) <div class="text-ink-muted">{{ $n->sender_code }}</div>@endif
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $n->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($n->status === 'sent')
                                    <span class="whitespace-nowrap rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">{{ match ($n->type) { 'kullanim' => 'ÜTS\'ye bildirildi', 'kullanim_iptal' => 'İptal edildi', 'almak_istemiyorum' => 'İşaretlendi', default => 'Kabul edildi' } }}</span>
                                    <div class="text-ink-muted mt-1 break-all">{{ $n->uts_notification_id }}</div>
                                @else
                                    <span class="whitespace-nowrap rounded-full bg-red-50 text-status-critical px-2 py-0.5 text-[12px]">Gönderilemedi</span>
                                    <div class="text-ink-muted mt-1">{{ $n->message }}</div>
                                @endif

                                @if ($canAct && $usage && $usage->cancelled_at === null)
                                    @if ($n->type === 'kullanim' && $n->status === 'failed' && $usage->utsStatus() === 'failed' && $usage->utsNotifications->last()->is($n))
                                        <button type="button" wire:click="retry({{ $n->id }})" wire:loading.attr="disabled" class="mt-2 whitespace-nowrap border border-line rounded-md px-3 py-1 text-[13px] text-ink hover:border-panel-900">Tekrar gönder</button>
                                    @elseif ($n->type === 'kullanim' && $n->status === 'sent' && $usage->reportedCode() === $n->uts_notification_id)
                                        <button type="button" wire:click="confirmCancel({{ $n->id }})" class="mt-2 whitespace-nowrap text-[13px] text-status-critical hover:underline">ÜTS'de iptal et</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif

    <x-modal :show="$cancelling !== null" title="Kullanım bildirimini iptal et" on-close="closeCancel">
        @if ($cancelling)
            <p class="text-[14px] text-ink">ÜTS'deki kullanım bildirimi iptal edilecek: <span class="font-medium">{{ $cancelling->product_name }}</span>@if ($cancelling->serial), seri {{ $cancelling->serial }}@elseif ($cancelling->lot), lot {{ $cancelling->lot }}@endif.</p>
            <p class="text-[13px] text-ink-muted mt-2">Ürün ÜTS'de yeniden kliniğinizin stoğunda görünür. Hastaya kullanım yanlış girildiyse iptalden sonra stok çıkışını Stok Hareketleri'nden iptal edin.</p>
            <div class="flex items-center gap-3 mt-6">
                <button type="button" wire:click="cancelUsage" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">ÜTS'de iptal et</button>
                <button type="button" wire:click="closeCancel" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        @endif
    </x-modal>
</div>

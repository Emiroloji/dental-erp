{{-- Sayım: beklenen ve sayılan miktar, fark ve nedeni; onayda düzeltme hareketi olur. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-[15px] font-medium text-ink">SY-00009</p>
            <p class="text-[12px] text-ink-muted">Merkez Şube, Varsayılan Depo</p>
        </div>
        <span class="text-[12px] px-2 py-0.5 rounded bg-status-warn-bg text-status-warn font-medium">Onay bekliyor</span>
    </div>

    <table class="mt-4 w-full text-[13px]">
        <caption class="sr-only">Sayım satırları</caption>
        <thead>
            <tr class="text-ink-muted text-[12px]">
                <th scope="col" class="text-left font-normal pb-1.5">Ürün</th>
                <th scope="col" class="text-right font-normal pb-1.5">Sistem</th>
                <th scope="col" class="text-right font-normal pb-1.5">Sayılan</th>
                <th scope="col" class="text-right font-normal pb-1.5">Fark</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([['Eldiven (M)', 400, 395, 'Hasar'], ['Enjektör 2ml', 180, 180, null], ['Pamuk rulo', 1200, 1188, 'Kayıp']] as [$product, $system, $counted, $reason])
                <tr class="border-t border-line">
                    <td class="py-2.5 text-ink">
                        {{ $product }}
                        @if ($reason)<span class="block text-[12px] text-ink-muted">{{ $reason }}</span>@endif
                    </td>
                    <td class="py-2.5 text-right tabular-nums text-ink-muted">{{ $system }}</td>
                    <td class="py-2.5 text-right tabular-nums text-ink">{{ $counted }}</td>
                    <td @class(['py-2.5 text-right tabular-nums', $counted < $system ? 'text-status-critical font-medium' : 'text-ink-muted'])>{{ $counted - $system ?: '0' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-4 text-[12px] text-ink-muted leading-relaxed">
        Onaylanınca −5 ve −12 adetlik düzeltme hareketi yazılır; kimin, neden onayladığı geçmişte kalır.
    </p>
</div>

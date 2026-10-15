{{-- Yetki: bir personelin modül modül okuma/yazma/silme kutucukları ve şube kapsamı. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-[15px] font-medium text-ink">Depo görevlisi</p>
            <p class="text-[12px] text-ink-muted">Kapsam: yalnızca kendi şubesi</p>
        </div>
    </div>

    <table class="mt-4 w-full text-[13px]">
        <caption class="sr-only">Modül yetkileri</caption>
        <thead>
            <tr class="text-ink-muted text-[12px]">
                <th scope="col" class="text-left font-normal pb-1.5">Modül</th>
                <th scope="col" class="font-normal pb-1.5">Okuma</th>
                <th scope="col" class="font-normal pb-1.5">Yazma</th>
                <th scope="col" class="font-normal pb-1.5">Silme</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([['Stok giriş/çıkış', 1, 1, 0], ['Transfer', 1, 1, 0], ['Satın alma', 1, 0, 0], ['Finans', 0, 0, 0]] as [$module, $read, $write, $delete])
                <tr class="border-t border-line">
                    <td class="py-2.5 text-ink">{{ $module }}</td>
                    @foreach ([$read, $write, $delete] as $on)
                        <td class="py-2.5 text-center">
                            <span @class(['inline-flex w-4 h-4 rounded-[3px] border items-center justify-center align-middle', $on ? 'bg-brand-500 border-brand-500' : 'border-line'])>
                                @if ($on)<svg viewBox="0 0 12 12" class="w-2.5 h-2.5 text-white" aria-hidden="true"><path d="M2.5 6.2 5 8.5l4.5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif
                            </span>
                            <span class="sr-only">{{ $on ? 'var' : 'yok' }}</span>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-4 text-[12px] text-ink-muted leading-relaxed">
        Her değişiklik, önceki ve yeni değeriyle denetim kaydına yazılır.
    </p>
</div>

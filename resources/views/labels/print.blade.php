<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiket — {{ $label['title'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #16211f; margin: 0; padding: 16px; }
        .toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; font-size: 14px; }
        .toolbar button, .toolbar input { font: inherit; padding: 6px 10px; border: 1px solid #d5dbd9; border-radius: 6px; background: #fff; }
        .sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(62mm, 1fr)); gap: 4mm; }
        .label { border: 1px dashed #c9d0ce; border-radius: 4px; padding: 3mm; display: flex; gap: 3mm; align-items: center; break-inside: avoid; height: 32mm; }
        .label svg { width: 26mm; height: 26mm; flex-shrink: 0; }
        .title { font-size: 11pt; font-weight: 600; line-height: 1.2; }
        .line { font-size: 9pt; color: #3d4a47; margin-top: 1mm; }
        .code { font-family: ui-monospace, monospace; font-size: 7pt; color: #7a8683; margin-top: 1.5mm; }
        @media print { .toolbar { display: none; } body { padding: 0; } .label { border-color: #e2e6e4; } }
    </style>
</head>
<body>
    <form class="toolbar" method="get">
        <button type="button" onclick="window.print()">Yazdır</button>
        <label>Adet <input type="number" name="adet" min="1" max="60" value="{{ $copies }}" style="width: 70px"></label>
        <button type="submit">Güncelle</button>
        <span style="color:#5c6d69">Hızlı İşlem ekranında okutulunca {{ str_starts_with($label['payload'], 'DERP:L:') ? 'bu lot' : 'bu ürün' }} doğrudan seçilir.</span>
    </form>

    <div class="sheet">
        @for ($i = 0; $i < $copies; $i++)
            <div class="label">
                {!! $qr !!}
                <div>
                    <div class="title">{{ $label['title'] }}</div>
                    @foreach ($label['lines'] as $line)
                        <div class="line">{{ $line }}</div>
                    @endforeach
                    <div class="code">{{ $label['payload'] }}</div>
                </div>
            </div>
        @endfor
    </div>
</body>
</html>

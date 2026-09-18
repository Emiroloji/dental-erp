<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #16211f; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        p.meta { color: #5c6d69; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e6e4; padding: 6px 8px; text-align: left; }
        th { background: #f6f7f6; }
        td.number { text-align: right; }
    </style>
</head>
<body>
    <h1>Stok Raporu</h1>
    <p class="meta">Oluşturulma: {{ now()->format('d.m.Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Ürün</th>
                <th>Kod</th>
                <th>Kategori</th>
                <th>Tedarikçi</th>
                <th>Mevcut Stok</th>
                <th>Birim Maliyet</th>
                <th>Toplam Değer</th>
                <th>Seviye</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['product']->name }}</td>
                    <td>{{ $row['product']->code ?? '—' }}</td>
                    <td>{{ $row['product']->category?->name ?? '—' }}</td>
                    <td>{{ $row['product']->supplier?->name ?? '—' }}</td>
                    <td class="number">{{ number_format($row['quantity'], 2) }}</td>
                    <td class="number">{{ number_format($row['product']->purchase_price, 2) }}</td>
                    <td class="number">{{ number_format($row['value'], 2) }}</td>
                    <td>{{ $row['level']->label() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Kayıt bulunamadı.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

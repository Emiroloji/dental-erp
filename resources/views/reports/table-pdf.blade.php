<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #16211f; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        p.meta { color: #5c6d69; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e6e4; padding: 5px 6px; text-align: left; }
        th { background: #f6f7f6; }
        td.number { text-align: right; }
        tfoot td { font-weight: bold; background: #f6f7f6; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $filters }} · Oluşturulma: {{ now()->format('d.m.Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td class="{{ is_int($cell) || is_float($cell) ? 'number' : '' }}">{{ is_float($cell) ? Number::format($cell, precision: 2) : ($cell ?? '—') }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headings) }}">Kayıt bulunamadı.</td>
                </tr>
            @endforelse
        </tbody>
        @if (! empty($totals))
            <tfoot>
                <tr>
                    @foreach ($totals as $cell)
                        <td class="{{ is_float($cell) ? 'number' : '' }}">{{ is_float($cell) ? Number::format($cell, precision: 2) : $cell }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>

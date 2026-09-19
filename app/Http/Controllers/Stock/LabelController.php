<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\ScanResolver;
use App\Support\QrCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Yazdırılabilir QR etiketleri (Aşama 20). Ürün etiketi barkodu olmayan
 * ürünler için; lot etiketi okutulunca doğrudan o lot seçilir (SKT takibi
 * için rafta lotu ayırt etmeyi sağlar). Organizasyon ve şube kapsamı model
 * kapsamlarından gelir: başka organizasyonun kaydı 404 döner.
 */
class LabelController
{
    public function product(Request $request, int $product): View
    {
        $model = Product::findOrFail($product);

        return $this->render($request, [
            'title' => $model->name,
            'lines' => array_filter([$model->code, $model->barcode ? "Barkod {$model->barcode}" : null]),
            'payload' => ScanResolver::productPayload($model),
        ]);
    }

    public function lot(Request $request, int $lot): View
    {
        $model = StockLot::whereHas('product')
            ->inBranches($request->user()->accessibleBranchIds(Module::StockMovement))
            ->with(['product', 'warehouse'])
            ->findOrFail($lot);

        return $this->render($request, [
            'title' => $model->product->name,
            'lines' => [
                'Lot '.($model->lot_no ?? '#'.$model->id),
                'SKT '.($model->expiry_date?->format('d.m.Y') ?? '—'),
                $model->warehouse->name,
            ],
            'payload' => ScanResolver::lotPayload($model),
        ]);
    }

    /**
     * @param  array{title: string, lines: array<int, string>, payload: string}  $label
     */
    private function render(Request $request, array $label): View
    {
        $copies = max(1, min(60, (int) $request->query('adet', 1)));

        return view('labels.print', [
            'label' => $label,
            'qr' => QrCode::svg($label['payload'], 120),
            'copies' => $copies,
        ]);
    }
}

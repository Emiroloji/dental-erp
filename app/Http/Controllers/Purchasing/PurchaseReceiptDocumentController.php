<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Support\PurchasingPermissions;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teslim alma belgesini (fatura/irsaliye) indirir. Belge yalnızca siparişi
 * görebilen kullanıcıya verilir; başka organizasyon veya kapsam dışı şube 404.
 */
class PurchaseReceiptDocumentController extends Controller
{
    public function __invoke(int $receipt, PurchasingPermissions $permissions): StreamedResponse
    {
        $receipt = PurchaseReceipt::findOrFail($receipt);

        // PurchaseOrder organizasyon scope'lu: başka organizasyonun siparişi bulunamaz.
        $order = PurchaseOrder::with('warehouse')->find($receipt->purchase_order_id);

        abort_unless($order && $permissions->canView(auth()->user(), $order), 404);
        abort_unless($receipt->document_path && Storage::disk('local')->exists($receipt->document_path), 404);

        return Storage::disk('local')->download($receipt->document_path, $receipt->document_name ?? basename($receipt->document_path));
    }
}

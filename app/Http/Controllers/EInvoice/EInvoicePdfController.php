<?php

namespace App\Http\Controllers\EInvoice;

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Gelen faturanın entegratörün ürettiği PDF görüntüsü. Fatura organizasyon
 * kapsamlıdır; başkasının faturası 404. Entegratör hatası 502 olarak döner.
 */
class EInvoicePdfController extends Controller
{
    public function __invoke(int $invoice, EInvoiceProvider $provider): Response
    {
        $invoice = EInvoice::findOrFail($invoice);

        try {
            $pdf = $provider->pdf($invoice->provider_uuid);
        } catch (EInvoiceException $e) {
            abort(502, $e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9_-]/', '', $invoice->invoice_number).'.pdf"',
        ]);
    }
}

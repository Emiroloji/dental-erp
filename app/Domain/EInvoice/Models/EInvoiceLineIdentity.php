<?php

namespace App\Domain\EInvoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * İlaç/tıbbi cihaz kaleminin takip kimliği: GTIN (UNO), lot, seri, üretim
 * ya da son kullanma tarihi (GİB İlaç ve Tıbbi Cihaz Fatura Kılavuzu).
 */
class EInvoiceLineIdentity extends Model
{
    public $timestamps = false;

    protected $fillable = ['e_invoice_line_id', 'scheme', 'gtin', 'lot', 'serial', 'production_date', 'expiry_date', 'raw'];

    protected $casts = [
        'production_date' => 'date',
        'expiry_date' => 'date',
    ];
}

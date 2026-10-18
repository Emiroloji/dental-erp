<?php

namespace App\Domain\EInvoice\Models;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entegratörden çekilen gelen e-fatura (Faz 6). Tutarlar kuruş; özgün UBL
 * XML'i özel diskte `xml_path` altında durur. Yalnızca EInvoiceSyncService
 * oluşturur; eşleşme alanları (tedarikçi, teslim, finans) sonradan dolar.
 */
class EInvoice extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'provider_uuid', 'invoice_number', 'profile', 'type', 'issue_date',
        'sender_tax_number', 'sender_name', 'currency', 'line_extension', 'tax_exclusive',
        'tax_total', 'payable', 'xml_path', 'supplier_id', 'purchase_receipt_id', 'finance_obligation_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'line_extension' => 'integer',
        'tax_exclusive' => 'integer',
        'tax_total' => 'integer',
        'payable' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(EInvoiceLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function financeObligation(): BelongsTo
    {
        return $this->belongsTo(FinanceObligation::class);
    }

    public function isMedical(): bool
    {
        return $this->profile === 'ILAC_TIBBICIHAZ';
    }
}

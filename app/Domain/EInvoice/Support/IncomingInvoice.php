<?php

namespace App\Domain\EInvoice\Support;

/**
 * Entegratörün gelen fatura listesindeki bir satır; ayrıntı XML'den okunur.
 */
final readonly class IncomingInvoice
{
    public function __construct(
        public string $uuid,
        public ?string $number,
        public ?string $senderTaxNumber,
        public ?string $senderName,
    ) {}
}

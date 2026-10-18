<?php

namespace App\Domain\EInvoice\Support;

/**
 * UBL faturasının bir kalemi; tutarlar kuruş, miktar ve KDV oranı metin.
 */
final readonly class ParsedLine
{
    /**
     * @param  list<TrackingIdentity>  $identities
     */
    public function __construct(
        public int $lineNo,
        public string $name,
        public ?string $sellerCode,
        public string $quantity,
        public ?string $unitCode,
        public int $unitPrice,
        public int $lineAmount,
        public ?string $vatRate,
        public array $identities,
    ) {}
}

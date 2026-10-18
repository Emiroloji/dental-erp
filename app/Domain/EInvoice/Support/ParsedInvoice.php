<?php

namespace App\Domain\EInvoice\Support;

/**
 * UBL-TR faturasından okunan başlık ve kalemler; tutarlar kuruş.
 */
final readonly class ParsedInvoice
{
    /**
     * @param  list<ParsedLine>  $lines
     */
    public function __construct(
        public string $uuid,
        public string $number,
        public ?string $profile,
        public ?string $type,
        public string $issueDate,
        public ?string $senderTaxNumber,
        public ?string $senderName,
        public string $currency,
        public int $lineExtension,
        public int $taxExclusive,
        public int $taxTotal,
        public int $payable,
        public array $lines,
    ) {}
}

<?php

namespace App\Domain\Uts\Support;

/**
 * "Almak istemediğim" sorgusunun bir sayfası. ÜTS bu sorguda sayfa numarası
 * yerine bir sonraki sayfanın başlangıç adresini (OFF) döndürür (3.4.25.2).
 */
final readonly class DeclinedReceiptPage
{
    public const PAGE_SIZE = 20;

    /**
     * @param  list<DeclinedReceipt>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextOffset,
    ) {}
}

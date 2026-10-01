<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS sorgu sonuçlarını en fazla 10'luk sayfalarla döndürür (SAN = sayfa no).
 */
final readonly class PendingReceiptPage
{
    public const PAGE_SIZE = 10;

    /**
     * @param  list<PendingReceipt>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public bool $hasMore,
    ) {}
}

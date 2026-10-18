<?php

namespace App\Domain\EInvoice\Support;

final readonly class IncomingPage
{
    /**
     * @param  list<IncomingInvoice>  $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {}
}

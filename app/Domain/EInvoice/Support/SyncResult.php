<?php

namespace App\Domain\EInvoice\Support;

final readonly class SyncResult
{
    public function __construct(
        public int $created,
        public int $skipped,
        public int $failed,
    ) {}
}

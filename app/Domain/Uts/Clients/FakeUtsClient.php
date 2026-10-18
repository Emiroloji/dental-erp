<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;

/**
 * Testler ve geliştirme için bellek içi ÜTS. Çağrıları kaydeder; kabul
 * edilen (receive) bildirimler bekleyenler listesinden düşer.
 */
class FakeUtsClient implements UtsClient
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var list<string> */
    private array $received = [];

    /**
     * @param  list<PendingReceipt>  $pending
     * @param  list<UtsItem>  $items
     */
    public function __construct(
        private readonly array $pending = [],
        private readonly array $items = [],
        private readonly ?UtsException $failure = null,
    ) {}

    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $this->calls[] = ['pendingReceipts', [$senderCode, $page]];

        if ($this->failure) {
            throw $this->failure;
        }

        $rows = array_values(array_filter($this->pending, fn (PendingReceipt $r) => ($senderCode === null || $r->senderCode === $senderCode)
            && ! in_array($r->notificationId, $this->received, true)));
        $slice = array_slice($rows, $page * PendingReceiptPage::PAGE_SIZE, PendingReceiptPage::PAGE_SIZE);

        return new PendingReceiptPage($slice, $page, count($rows) > ($page + 1) * PendingReceiptPage::PAGE_SIZE);
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $this->calls[] = ['lookup', [$uno, $lot, $serial]];

        if ($this->failure) {
            throw $this->failure;
        }

        return array_values(array_filter($this->items, fn (UtsItem $i) => $i->uno === $uno
            && ($lot === null || $i->lot === $lot)
            && ($serial === null || $i->serial === $serial)));
    }

    public function receive(string $notificationId, ?int $quantity = null): string
    {
        $this->calls[] = ['receive', [$notificationId, $quantity]];

        if ($this->failure) {
            throw $this->failure;
        }

        $this->received[] = $notificationId;

        return 'sahte-alma-'.count($this->received);
    }

    public function name(): string
    {
        return 'Sahte ÜTS';
    }
}

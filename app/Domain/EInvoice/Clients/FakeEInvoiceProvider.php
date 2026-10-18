<?php

namespace App\Domain\EInvoice\Clients;

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Support\IncomingInvoice;
use App\Domain\EInvoice\Support\IncomingPage;
use Carbon\CarbonInterface;

/**
 * Testler ve geliştirme için bellek içi entegratör: UUID → XML. İstenen
 * başlangıç tarihlerini kaydeder.
 */
class FakeEInvoiceProvider implements EInvoiceProvider
{
    /** @var list<CarbonInterface> */
    public array $sinceDates = [];

    /**
     * @param  array<string, string>  $documents
     */
    public function __construct(
        private readonly array $documents = [],
        private readonly ?EInvoiceException $failure = null,
    ) {}

    public function incoming(CarbonInterface $since, int $page): IncomingPage
    {
        $this->sinceDates[] = $since->copy();
        $this->fail();

        $items = array_map(fn (string $uuid) => new IncomingInvoice($uuid, null, null, null), array_keys($this->documents));

        return new IncomingPage($page === 1 ? $items : [], false);
    }

    public function xml(string $uuid): string
    {
        $this->fail();

        return $this->documents[$uuid] ?? throw new EInvoiceException('Fatura bulunamadı.');
    }

    public function pdf(string $uuid): string
    {
        $this->fail();

        return "%PDF-1.4 sahte {$uuid}";
    }

    public function name(): string
    {
        return 'Sahte entegratör';
    }

    private function fail(): void
    {
        if ($this->failure) {
            throw $this->failure;
        }
    }
}

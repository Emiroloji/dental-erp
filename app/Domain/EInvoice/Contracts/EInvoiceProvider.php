<?php

namespace App\Domain\EInvoice\Contracts;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Support\IncomingPage;
use Carbon\CarbonInterface;

/**
 * e-Fatura entegratörü (Faz 6). Uygulamalar: NilveraProvider (gerçek),
 * FakeEInvoiceProvider (test), UnconfiguredEInvoiceProvider (anahtar yok).
 * Fatura içeriği her entegratörde aynı UBL-TR XML'dir; okunması
 * UblInvoiceParser'ın işidir, sürücü yalnızca taşır.
 */
interface EInvoiceProvider
{
    /**
     * Entegratöre $since'ten sonra düşen gelen faturalar; sayfa 1'den başlar.
     *
     * @throws EInvoiceException
     */
    public function incoming(CarbonInterface $since, int $page): IncomingPage;

    /**
     * Faturanın UBL-TR XML'i.
     *
     * @throws EInvoiceException
     */
    public function xml(string $uuid): string;

    /**
     * Faturanın entegratörün ürettiği PDF görüntüsü.
     *
     * @throws EInvoiceException
     */
    public function pdf(string $uuid): string;

    public function name(): string;
}

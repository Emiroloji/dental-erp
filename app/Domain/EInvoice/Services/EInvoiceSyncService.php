<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Support\ParsedInvoice;
use App\Domain\EInvoice\Support\SyncResult;
use App\Domain\EInvoice\Support\UblInvoiceParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Entegratördeki yeni gelen faturaları çeker, XML'i saklar ve okur (Faz 6).
 * Oturum olmadan (kuyrukta) da çalışır: organizasyon bağlantıdan gelir ve
 * sorgular kapsamı açıkça verir. Aynı ETTN ikinci kez yazılmaz; okunamayan
 * fatura atlanır ve sonraki eşitlemede yeniden denenir.
 */
class EInvoiceSyncService
{
    private const MAX_PAGES = 100;

    public function __construct(
        private readonly EInvoiceProviderFactory $providers,
        private readonly UblInvoiceParser $parser,
    ) {}

    /**
     * @throws EInvoiceException liste alınamazsa (son eşitleme zamanı değişmez)
     */
    public function sync(EInvoiceConnection $connection): SyncResult
    {
        $provider = $this->providers->for($connection);
        $startedAt = now();
        $since = $connection->last_synced_at?->copy()->subDay() ?? now()->subDays((int) config('einvoice.initial_days'));
        $created = $skipped = $failed = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $provider->incoming($since, $page);

            foreach ($result->items as $item) {
                if ($this->exists($connection->organization_id, $item->uuid)) {
                    $skipped++;

                    continue;
                }

                try {
                    $xml = $provider->xml($item->uuid);
                    $parsed = $this->parser->parse($xml);

                    if (strcasecmp($parsed->uuid, $item->uuid) !== 0) {
                        throw new EInvoiceException("Listedeki ETTN ({$item->uuid}) belgedekiyle ({$parsed->uuid}) aynı değil.");
                    }

                    $this->store($connection->organization_id, $parsed, $xml);
                    $created++;
                } catch (EInvoiceException $e) {
                    Log::warning('e-Fatura okunamadı, atlandı', ['organization_id' => $connection->organization_id, 'uuid' => $item->uuid, 'error' => $e->getMessage()]);
                    $failed++;
                }
            }

            if (! $result->hasMore) {
                break;
            }
        }

        $connection->forceFill(['last_synced_at' => $startedAt])->save();

        return new SyncResult($created, $skipped, $failed);
    }

    private function exists(int $organizationId, string $uuid): bool
    {
        return EInvoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereRaw('lower(provider_uuid) = ?', [strtolower($uuid)])
            ->exists();
    }

    private function store(int $organizationId, ParsedInvoice $parsed, string $xml): void
    {
        $path = "e-invoices/{$organizationId}/".strtolower($parsed->uuid).'.xml';
        Storage::disk('local')->put($path, $xml);

        DB::transaction(function () use ($organizationId, $parsed, $path) {
            $invoice = EInvoice::create([
                'organization_id' => $organizationId,
                'provider_uuid' => strtolower($parsed->uuid),
                'invoice_number' => $parsed->number,
                'profile' => $parsed->profile,
                'type' => $parsed->type,
                'issue_date' => $parsed->issueDate,
                'sender_tax_number' => $parsed->senderTaxNumber,
                'sender_name' => $parsed->senderName,
                'currency' => $parsed->currency,
                'line_extension' => $parsed->lineExtension,
                'tax_exclusive' => $parsed->taxExclusive,
                'tax_total' => $parsed->taxTotal,
                'payable' => $parsed->payable,
                'xml_path' => $path,
            ]);

            foreach ($parsed->lines as $line) {
                $model = $invoice->lines()->create([
                    'line_no' => $line->lineNo,
                    'name' => mb_substr($line->name, 0, 255),
                    'seller_code' => $line->sellerCode,
                    'quantity' => $line->quantity,
                    'unit_code' => $line->unitCode,
                    'unit_price' => $line->unitPrice,
                    'line_amount' => $line->lineAmount,
                    'vat_rate' => $line->vatRate,
                ]);

                foreach ($line->identities as $identity) {
                    $model->identities()->create([
                        'scheme' => $identity->scheme,
                        'gtin' => $identity->gtin,
                        'lot' => $identity->lot,
                        'serial' => $identity->serial,
                        'production_date' => $identity->productionDate,
                        'expiry_date' => $identity->expiryDate,
                        'raw' => mb_substr($identity->raw, 0, 255),
                    ]);
                }
            }
        });
    }
}

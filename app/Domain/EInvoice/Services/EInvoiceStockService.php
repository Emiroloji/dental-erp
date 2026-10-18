<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceLine;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gelen e-faturadan onaylı stok girişi (Aşama 56). Taslak faturadan doldurulur
 * (ürün eşleşmesi, adet, lot, son kullanma tarihi, seriler); kullanıcı
 * düzeltip onaylar. Girişler StockMovementService::in ile, faturaya bağlı ve
 * hep birlikte yapılır; biri hata verirse hiçbiri yazılmaz. Satın alma
 * teslimiyle eşleşmiş fatura zaten stoğa girmiştir, alınmaz.
 */
class EInvoiceStockService
{
    /** Adet sayılan UBL birim kodları; diğerlerinde miktar kullanıcıdan istenir. */
    private const PIECE_UNITS = ['C62', 'NIU', 'H87'];

    public function __construct(private readonly StockMovementService $stock) {}

    /**
     * Fatura kalemlerinden giriş satırları. Seri takipli üründe seriler lota
     * göre ayrı satırlara bölünür; birden çok lotu karışık taşıyan lot takipli
     * kalem (lot başına adet faturada yok) otomatik doldurulmaz.
     *
     * @return list<array{line_id: int, name: string, include: bool, product_id: ?int, quantity: string, lot_no: string, expiry_date: string, serials: list<string>, temperature: string, temperature_note: string, warning: ?string}>
     */
    public function draft(EInvoice $invoice): array
    {
        $rows = [];

        foreach ($invoice->lines()->with(['identities', 'product'])->get() as $line) {
            $product = $line->product;
            $lots = $line->identities->pluck('lot')->filter()->unique()->values();
            $base = [
                'line_id' => $line->id,
                'name' => $line->name,
                'include' => $product !== null,
                'product_id' => $product?->id,
                'quantity' => in_array($line->unit_code, self::PIECE_UNITS, true) ? $this->number((float) $line->quantity) : '',
                'lot_no' => (string) ($lots->first() ?? ''),
                'expiry_date' => (string) ($line->identities->first()?->expiry_date?->toDateString() ?? ''),
                'serials' => [],
                'temperature' => '',
                'temperature_note' => '',
                'warning' => $product ? null : 'Ürün kartıyla eşleşmedi. Stoğa girecekse işaretleyip kartı seçin; hizmet kalemiyse boş bırakın.',
            ];

            if ($product?->tracks_serials && $line->identities->whereNotNull('serial')->isNotEmpty()) {
                foreach ($line->identities->whereNotNull('serial')->groupBy(fn ($identity) => (string) $identity->lot) as $lot => $identities) {
                    $rows[] = array_merge($base, [
                        'quantity' => (string) $identities->count(),
                        'lot_no' => (string) $lot,
                        'expiry_date' => (string) ($identities->first()->expiry_date?->toDateString() ?? ''),
                        'serials' => $identities->pluck('serial')->values()->all(),
                    ]);
                }

                continue;
            }

            if ($lots->count() > 1) {
                $base['include'] = false;
                $base['warning'] = 'Faturada birden çok lot var ama lot başına adet yok; bu kalemi Stok Girişi\'nden lot lot girin.';
            }

            $rows[] = $base;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  draft() biçiminde, kullanıcının düzelttiği satırlar
     * @return Collection<int, StockMovement>
     *
     * @throws EInvoiceException
     */
    public function receive(EInvoice $invoice, Warehouse $warehouse, array $rows, User $actor): Collection
    {
        $this->ensureReceivable($invoice);

        $rows = array_values(array_filter($rows, fn (array $row) => ! empty($row['include'])));
        if ($rows === []) {
            throw new EInvoiceException('Stoğa alınacak kalem seçin.');
        }

        $lines = EInvoiceLine::where('e_invoice_id', $invoice->id)->get()->keyBy('id');
        $lineTotals = [];

        foreach ($rows as $row) {
            $line = $lines[$row['line_id'] ?? 0] ?? throw new EInvoiceException('Faturada olmayan bir kalem gönderildi.');
            $quantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0.0;

            if (empty($row['product_id']) || $quantity <= 0) {
                throw new EInvoiceException("\"{$line->name}\" için ürün kartı ve miktar seçin ya da kalemi stoğa alınacaklardan çıkarın.");
            }

            $lineTotals[$line->id] = ($lineTotals[$line->id] ?? 0) + $quantity;
        }

        return DB::transaction(function () use ($invoice, $warehouse, $rows, $lines, $lineTotals, $actor) {
            $locked = EInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->ensureReceivable($locked);

            $movements = collect($rows)->map(function (array $row) use ($locked, $warehouse, $lines, $lineTotals, $actor) {
                $line = $lines[$row['line_id']];
                $product = Product::findOrFail($row['product_id']);
                $quantity = (float) $row['quantity'];

                try {
                    return $this->stock->in($product, $warehouse, $quantity, [
                        'lot_no' => trim((string) ($row['lot_no'] ?? '')) ?: null,
                        'expiry_date' => trim((string) ($row['expiry_date'] ?? '')) ?: null,
                        'unit_cost' => round($line->line_amount / 100 / $lineTotals[$line->id], 4),
                    ], $actor, "e-Fatura {$locked->invoice_number}, {$locked->sender_name}", $locked, [
                        'serials' => array_values(array_filter(array_map('strval', $row['serials'] ?? []))),
                        'temperature' => $row['temperature'] ?? null,
                        'temperature_note' => $row['temperature_note'] ?? null,
                    ]);
                } catch (EInvoiceException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new EInvoiceException("{$product->name}: {$e->getMessage()}", 0, $e);
                }
            });

            $locked->update(['stocked_at' => now(), 'stocked_by' => $actor->id]);

            return $movements;
        });
    }

    /**
     * Faturadan yapılan girişleri iptal eder (ters kayıt) ve faturayı yeniden
     * stoğa alınabilir yapar. Ürün o arada kullanıldıysa iptal reddedilir.
     *
     * @throws EInvoiceException
     */
    public function undo(EInvoice $invoice, User $actor): void
    {
        if ($invoice->stocked_at === null) {
            throw new EInvoiceException('Bu fatura stoğa alınmamış.');
        }

        try {
            DB::transaction(function () use ($invoice, $actor) {
                $movements = StockMovement::where('type', StockMovementType::In->value)
                    ->where('related_entity_type', $invoice->getMorphClass())
                    ->where('related_entity_id', $invoice->id)
                    ->withoutCancelled()
                    ->get();

                foreach ($movements as $movement) {
                    $this->stock->cancel($movement, $actor);
                }

                $invoice->update(['stocked_at' => null, 'stocked_by' => null]);
            });
        } catch (Throwable $e) {
            throw new EInvoiceException('Stok girişi geri alınamadı: '.$e->getMessage(), 0, $e);
        }
    }

    /** Fatura stoğa alınamıyorsa nedeni; alınabiliyorsa null. */
    public function refusal(EInvoice $invoice): ?string
    {
        return match (true) {
            $invoice->stocked_at !== null => 'Bu fatura zaten stoğa alındı.',
            $invoice->purchase_receipt_id !== null => 'Bu fatura bir satın alma teslimiyle eşleşmiş; ürünler teslim alınırken zaten stoğa girdi.',
            $invoice->type === 'IADE' => 'İade faturası stok girişi değildir; iadeyi İadeler ekranından yapın.',
            $invoice->currency !== 'TRY' => 'Döviz faturası buradan stoğa alınamaz (maliyet TL tutulur); kalemleri Stok Girişi\'nden girin.',
            default => null,
        };
    }

    private function ensureReceivable(EInvoice $invoice): void
    {
        if ($reason = $this->refusal($invoice)) {
            throw new EInvoiceException($reason);
        }
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

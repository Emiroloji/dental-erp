<?php

namespace App\Domain\EInvoice\Clients;

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceAuthException;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Support\IncomingInvoice;
use App\Domain\EInvoice\Support\IncomingPage;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nilvera e-Fatura REST API (developer.nilvera.com). Kimlik doğrulama
 * "Authorization: Bearer <API anahtarı>"; anahtar günlüğe ve hata
 * mesajlarına yazılmaz. xml/pdf uçlarının cevap biçimi belgede yazmadığından
 * ham dosya, JSON içinde metin ve base64 kabul edilir.
 */
class NilveraProvider implements EInvoiceProvider
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'Nilvera';
    }

    public function incoming(CarbonInterface $since, int $page): IncomingPage
    {
        $json = $this->get('/einvoice/Purchase', [
            'Page' => $page,
            'PageSize' => self::PAGE_SIZE,
            'StartDate' => $since->format('Y-m-d\TH:i:s'),
            'EndDate' => now()->addDay()->format('Y-m-d\TH:i:s'),
            'DateFilterType' => 'CreatedDate',
            'SortColumn' => 'CreatedDate',
            'SortType' => 'ASC',
        ])->json();

        $rows = array_values(array_filter((array) ($json['Content'] ?? []), 'is_array'));
        $items = array_map(fn (array $row) => new IncomingInvoice(
            (string) ($row['UUID'] ?? ''),
            self::text($row['InvoiceNumber'] ?? null),
            self::text($row['SenderTaxNumber'] ?? null),
            self::text($row['SenderName'] ?? null),
        ), $rows);

        $hasMore = isset($json['TotalPages'])
            ? $page < (int) $json['TotalPages']
            : count($items) >= self::PAGE_SIZE;

        return new IncomingPage(array_values(array_filter($items, fn ($item) => $item->uuid !== '')), $hasMore);
    }

    public function xml(string $uuid): string
    {
        $content = $this->document($this->get('/einvoice/Purchase/'.rawurlencode($uuid).'/xml')->body(), fn (string $v) => str_starts_with(ltrim($v, "\xEF\xBB\xBF \n\r\t"), '<'));

        if ($content === null) {
            throw new EInvoiceException('Entegratör fatura XML\'ini tanınmayan bir biçimde döndürdü.');
        }

        return $content;
    }

    public function pdf(string $uuid): string
    {
        $content = $this->document($this->get('/einvoice/Purchase/'.rawurlencode($uuid).'/pdf')->body(), fn (string $v) => str_starts_with($v, '%PDF'));

        if ($content === null) {
            throw new EInvoiceException('Entegratör fatura PDF\'ini tanınmayan bir biçimde döndürdü.');
        }

        return $content;
    }

    /**
     * Gövde ham belge, JSON içinde metin ya da base64 olabilir.
     *
     * @param  callable(string): bool  $looksRight
     */
    private function document(string $body, callable $looksRight): ?string
    {
        $candidates = [$body];

        if (is_string($decoded = json_decode($body, true))) {
            $candidates[] = $decoded;
        }

        foreach ($candidates as $candidate) {
            if ($looksRight($candidate)) {
                return $candidate;
            }

            $binary = base64_decode(trim($candidate), true);
            if ($binary !== false && $looksRight($binary)) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($this->apiKey)
                ->timeout($this->timeout)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            Log::warning('e-Fatura bağlantı hatası', ['path' => $path, 'error' => $e->getMessage()]);

            throw new EInvoiceException('e-Fatura servisi şu an yanıt vermiyor. Lütfen biraz sonra tekrar deneyin.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new EInvoiceAuthException('e-Fatura API anahtarı geçersiz, süresi dolmuş ya da yetkisi yetersiz. Ayarlar ekranından yeni bir anahtar girin.');
        }

        if ($response->failed()) {
            $json = (array) $response->json();
            $error = (array) ($json['Errors'][0] ?? []);
            $message = self::text($error['Detail'] ?? null) ?? self::text($error['Description'] ?? null) ?? self::text($json['Message'] ?? null);
            Log::warning('e-Fatura hata yanıtı', ['path' => $path, 'status' => $response->status(), 'mesaj' => $message]);

            throw new EInvoiceException($message ?? 'e-Fatura servisi isteği reddetti. Lütfen biraz sonra tekrar deneyin.');
        }

        return $response;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}

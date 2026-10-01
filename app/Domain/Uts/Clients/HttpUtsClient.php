<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ÜTS REST servisleri (Takip ve İzleme Web Servis Tanımları rev. 1.47).
 * Kimlik doğrulama her istekte `utsToken` başlığıdır (doküman 2.1). Token
 * günlüğe ve hata mesajlarına yazılmaz.
 */
class HttpUtsClient implements UtsClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'ÜTS';
    }

    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $body = ['SAN' => $page];

        if ($senderCode !== null) {
            $body['GKK'] = $senderCode;
        }

        $rows = $this->rows($this->post('/UTS/uh/rest/bildirim/alma/bekleyenler/sorgula', $body));
        $items = array_map(fn (array $row) => PendingReceipt::fromArray($row), $rows);

        return new PendingReceiptPage($items, $page, count($items) >= PendingReceiptPage::PAGE_SIZE);
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $body = ['UNO' => $uno, 'SAN' => 0];

        if ($lot !== null && $lot !== '') {
            $body['LNO'] = $lot;
        }

        if ($serial !== null && $serial !== '') {
            $body['SNO'] = $serial;
        }

        return array_map(fn (array $row) => UtsItem::fromArray($row), $this->rows($this->post('/UTS/uh/rest/tekilUrun/sorgula', $body)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['utsToken' => $this->token])
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($path, $body);
        } catch (ConnectionException $e) {
            Log::warning('ÜTS bağlantı hatası', ['path' => $path, 'error' => $e->getMessage()]);

            throw new UtsException('ÜTS şu an yanıt vermiyor. Lütfen biraz sonra tekrar deneyin.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new UtsAuthException('ÜTS token\'ı geçersiz veya süresi dolmuş. Ayarlar ekranından yeni bir sistem token\'ı girin.');
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        $this->throwOnErrorMessages($json, $response, $path);

        if ($response->failed()) {
            Log::warning('ÜTS hata yanıtı', ['path' => $path, 'status' => $response->status()]);

            throw new UtsException('ÜTS isteği reddetti veya şu an cevap veremiyor. Lütfen biraz sonra tekrar deneyin.');
        }

        return $json;
    }

    /**
     * MSJ listesindeki HATA tipli ilk mesaj kullanıcıya gösterilir (doküman 3.3.1).
     *
     * @param  array<string, mixed>  $json
     */
    private function throwOnErrorMessages(array $json, Response $response, string $path): void
    {
        foreach ((array) ($json['MSJ'] ?? []) as $message) {
            if (is_array($message) && ($message['TIP'] ?? null) === 'HATA') {
                Log::warning('ÜTS hata mesajı', ['path' => $path, 'status' => $response->status(), 'kod' => $message['KOD'] ?? null, 'mesaj' => $message['MET'] ?? null]);

                throw new UtsException((string) ($message['MET'] ?? 'ÜTS bir hata döndürdü.'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rows(array $json): array
    {
        return array_values(array_filter((array) ($json['SNC'] ?? []), 'is_array'));
    }
}

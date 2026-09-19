<?php

namespace App\Domain\Assistant\Interpreters;

use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Exceptions\InterpreterException;
use App\Domain\Assistant\Support\InterpreterContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini API (generateContent, yapılandırılmış JSON çıktısı).
 * Anahtar yalnızca config('services.gemini.key') üzerinden, yani .env'deki
 * GEMINI_API_KEY'den okunur; koda veya veritabanına yazılmaz.
 */
class GeminiQueryInterpreter implements QueryInterpreter
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $endpoint,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'Gemini';
    }

    public function interpret(string $question, InterpreterContext $context): array
    {
        try {
            $response = Http::baseUrl(rtrim($this->endpoint, '/'))
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeout)
                // Geçici yoğunluk (503) için iki kez daha denenir; kota (429) beklenmez.
                ->retry(3, 1000, fn ($exception) => $exception instanceof RequestException && $exception->response->status() === 503, throw: false)
                ->acceptJson()
                ->post("/models/{$this->model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $context->instructions()]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $question]]]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => self::geminiSchema(InterpreterContext::responseSchema()),
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Rapor asistanı: Gemini bağlantı hatası', ['error' => $e->getMessage()]);

            throw new InterpreterException('Yapay zekâ servisine ulaşılamadı. Lütfen biraz sonra tekrar deneyin.');
        }

        if ($response->failed()) {
            Log::warning('Rapor asistanı: Gemini hata yanıtı', ['status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new InterpreterException(match ($response->status()) {
                400, 401, 403 => 'Yapay zekâ servisi yapılandırması geçersiz (API anahtarını kontrol edin).',
                404 => 'Yapay zekâ modeli bulunamadı (GEMINI_MODEL ayarını kontrol edin).',
                503 => 'Yapay zekâ servisi şu an çok yoğun. Lütfen biraz sonra tekrar deneyin.',
                429 => 'Yapay zekâ servisinin kullanım kotası doldu. Lütfen daha sonra tekrar deneyin.',
                default => 'Yapay zekâ servisi şu an cevap veremiyor. Lütfen biraz sonra tekrar deneyin.',
            });
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded)) {
            Log::warning('Rapor asistanı: Gemini geçersiz cevap', ['finishReason' => $response->json('candidates.0.finishReason')]);

            throw new InterpreterException('Yapay zekâ servisinden anlaşılır bir cevap alınamadı. Soruyu farklı ifade etmeyi deneyin.');
        }

        return $decoded;
    }

    /**
     * Gemini'nin responseSchema biçimi (OpenAPI alt kümesi, büyük harfli tipler).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function geminiSchema(array $schema): array
    {
        if (isset($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(fn (array $property) => self::geminiSchema($property), $schema['properties']);
        }

        return $schema;
    }
}

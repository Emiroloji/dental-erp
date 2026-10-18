<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS hastanın vücudundan çıkarma bildirimi (rev. 1.99, 3.1.22). Ürün ve hasta
 * alanları kullanım bildirimiyle aynıdır; GIT çıkarma tarihidir. GRK gerekçe
 * zorunlu, DIGER'de DGA açıklama zorunludur.
 */
final readonly class RemovalReport
{
    public const REASONS = [
        'DOKU_UYUMSUZLUGU' => 'Doku uyumsuzluğu',
        'REVIZYON' => 'Revizyon',
        'KULLANIM_OMRUNU_DOLDURMASI' => 'Kullanım ömrünü doldurma',
        'GERI_CAGIRMA' => 'Geri çağırma',
        'DIGER' => 'Diğer',
    ];

    public function __construct(
        public UsageReport $usage,
        public string $reason,
        public ?string $description,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = $this->usage->toPayload() + ['GRK' => $this->reason];

        if ($this->reason === 'DIGER') {
            $payload['DGA'] = $this->description;
        }

        return $payload;
    }
}

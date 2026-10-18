<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS İmha/Bertaraf (rev. 1.99, 3.1.21: GRK gerekçe, BNO belge no zorunlu) ya
 * da HEK/Zayiat (3.1.17: TUR türü) bildirimi. Seri takipli üründe SNO, lot
 * takipli üründe LNO + ADT gider; "DIGER" seçilince açıklama zorunludur
 * (imhada DGA, HEK'te DTA).
 */
final readonly class DisposalReport
{
    public const TYPE_IMHA = 'imha';

    public const TYPE_HEK = 'hek';

    public const IMHA_REASONS = [
        'SON_KULLANMA_TARIHI_GECMIS' => 'Son kullanma tarihi geçmiş',
        'TASIMA_VE_SAKLAMA_KOSULLARI_BOZULMUS' => 'Taşıma ve saklama koşulları bozulmuş',
        'KURUM_KARARI' => 'Kurum kararı',
        'GONULLU_GERI_CEKME' => 'Gönüllü geri çekme',
        'HASTANIN_VUCUDUNDAN_CIKARILMIS' => 'Hastanın vücudundan çıkarılmış',
        'SAHTE_KACAK' => 'Sahte / kaçak',
        'DIGER' => 'Diğer',
    ];

    public const HEK_TYPES = [
        'HEK' => 'HEK (hurda, enkaz, köhne)',
        'CALINMA' => 'Çalınma',
        'YANGIN' => 'Yangın',
        'DOGAL_AFET' => 'Doğal afet',
        'STOK_DUZELTME' => 'Stok düzeltme',
        'DIGER' => 'Diğer',
    ];

    public function __construct(
        public string $type,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public string $code,
        public ?string $description,
        public ?string $documentNo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['UNO' => $this->uno];

        if ($this->serial !== null) {
            $payload['SNO'] = $this->serial;
        } else {
            $payload['LNO'] = $this->lot;
            $payload['ADT'] = $this->quantity;
        }

        if ($this->type === self::TYPE_IMHA) {
            $payload['GRK'] = $this->code;
            if ($this->code === 'DIGER') {
                $payload['DGA'] = $this->description;
            }
            $payload['BNO'] = $this->documentNo;
        } else {
            $payload['TUR'] = $this->code;
            if ($this->code === 'DIGER') {
                $payload['DTA'] = $this->description;
            }
        }

        return $payload;
    }

    public function path(): string
    {
        return $this->type === self::TYPE_IMHA ? 'imhaBertaraf' : 'hekZayiat';
    }
}

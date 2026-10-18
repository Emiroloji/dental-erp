<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS Verme bildirimi içeriği (rev. 1.99, 3.1.4): ürün başka bir kuruma
 * verildiğinde — klinikte tedarikçiye iade. Seri takipli üründe SNO, lot
 * takipli üründe LNO + ADT gider; KUN alıcı kurumun ÜTS numarası, BNO iade
 * faturası/irsaliyesi numarasıdır.
 */
final readonly class GiveReport
{
    public function __construct(
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public int $institutionNo,
        public string $documentNo,
        public string $date,
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

        return $payload + ['KUN' => $this->institutionNo, 'BNO' => $this->documentNo, 'GIT' => $this->date];
    }
}

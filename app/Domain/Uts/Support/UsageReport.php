<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS Kullanım bildirimi içeriği (rev. 1.99, 3.1.9). Seri takipli üründe
 * SNO, lot takipli üründe LNO + ADT gider. Kimlik numarası türüne göre
 * TKN / YKN / PAN alanına; kimliği belirsiz hastada KTN olarak kayıt no gider.
 */
final readonly class UsageReport
{
    public function __construct(
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public string $usedOn,
        public string $firstName,
        public string $lastName,
        public string $identityCode,
        public ?string $identityField,
        public ?string $identityNo,
        public int $personNo,
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

        $payload += ['GIT' => $this->usedOn, 'HAA' => $this->firstName, 'HAS' => $this->lastName, 'TUR' => $this->identityCode];

        if ($this->identityField !== null) {
            $payload[$this->identityField] = $this->identityNo;
        } else {
            $payload['KTN'] = $this->personNo;
        }

        return $payload;
    }
}

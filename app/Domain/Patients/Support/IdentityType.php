<?php

namespace App\Domain\Patients\Support;

/**
 * Hastanın kimlik türü; ÜTS Kullanım bildiriminin TUR alanına ve kimlik
 * numarasının gideceği alana (TKN / YKN / PAN) karşılık gelir (rev. 1.99, 3.1.9).
 */
enum IdentityType: string
{
    case Tc = 'tc';
    case Foreign = 'foreign';
    case Passport = 'passport';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Tc => 'T.C. kimlik no',
            self::Foreign => 'Yabancı kimlik no',
            self::Passport => 'Pasaport no',
            self::Unknown => 'Kimliği belirsiz',
        };
    }

    public function utsCode(): string
    {
        return match ($this) {
            self::Tc => 'TC_KIMLIK_NUMARASI_VAR',
            self::Foreign => 'YABANCI_KIMLIK_NUMARASI_VAR',
            self::Passport => 'PASAPORT_NUMARASI_VAR',
            self::Unknown => 'KIMLIGI_BELIRSIZ',
        };
    }

    /** Kimlik numarasının ÜTS'de gideceği alan; kimliği belirsizde yoktur. */
    public function utsField(): ?string
    {
        return match ($this) {
            self::Tc => 'TKN',
            self::Foreign => 'YKN',
            self::Passport => 'PAN',
            self::Unknown => null,
        };
    }

    public function needsNumber(): bool
    {
        return $this !== self::Unknown;
    }
}

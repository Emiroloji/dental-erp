<?php

namespace App\Domain\Patients\Support;

/**
 * Kimlik numarası doğrulama, normalleştirme, arama özeti ve maskeleme.
 * Numara veritabanında şifreli durur; arama ve mükerrer kontrolü APP_KEY ile
 * üretilen HMAC özeti üzerinden tam eşleşmeyle yapılır.
 */
final class PatientIdentity
{
    public static function normalize(string $number): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $number));
    }

    public static function isValidTc(string $number): bool
    {
        if (! preg_match('/^[1-9]\d{10}$/', $number)) {
            return false;
        }

        $d = array_map('intval', str_split($number));
        $tenth = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7) - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;

        return ($tenth + 10) % 10 === $d[9]
            && array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    /**
     * Türüne göre geçerliyse null, değilse kullanıcıya gösterilecek hata.
     */
    public static function validationError(IdentityType $type, ?string $number): ?string
    {
        if (! $type->needsNumber()) {
            return null;
        }

        $number = self::normalize((string) $number);

        return match ($type) {
            IdentityType::Tc => self::isValidTc($number) ? null : 'T.C. kimlik numarası geçersiz; 11 haneyi kontrol edin.',
            IdentityType::Foreign => preg_match('/^\d{11}$/', $number) ? null : 'Yabancı kimlik numarası 11 haneli olmalı.',
            IdentityType::Passport => preg_match('/^[A-Z0-9]{5,20}$/', $number) ? null : 'Pasaport numarası 5–20 harf veya rakam olmalı.',
            IdentityType::Unknown => null,
        };
    }

    public static function hash(string $number): string
    {
        return hash_hmac('sha256', self::normalize($number), (string) config('app.key'));
    }

    public static function mask(string $number): string
    {
        $length = mb_strlen($number);

        return $length <= 5
            ? str_repeat('*', $length)
            : mb_substr($number, 0, 3).str_repeat('*', $length - 5).mb_substr($number, -2);
    }
}

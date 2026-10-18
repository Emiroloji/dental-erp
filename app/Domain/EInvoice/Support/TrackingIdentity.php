<?php

namespace App\Domain\EInvoice\Support;

/**
 * İlaç ve tıbbi cihaz faturasında kalemin takip kimliği (GİB "İlaç ve Tıbbi
 * Cihaz Teslimlerine İlişkin Fatura Teknik Kılavuzu" V1.2):
 *  TIBBICIHAZ: (UNO)ürün no(LNO)lot(SNO)seri(URT)üretim YYAAGG
 *  ILAC:       (GTIN)…(BN)parti(SN)seri(XD)son kullanma YYAAGG
 * DIGER (takip dışı kalem) kimlik sayılmaz.
 */
final readonly class TrackingIdentity
{
    public function __construct(
        public string $scheme,
        public ?string $gtin,
        public ?string $lot,
        public ?string $serial,
        public ?string $productionDate,
        public ?string $expiryDate,
        public string $raw,
    ) {}

    public static function parse(string $scheme, string $value): ?self
    {
        $scheme = strtoupper(trim($scheme));

        if (! in_array($scheme, ['TIBBICIHAZ', 'ILAC'], true)) {
            return null;
        }

        preg_match_all('/\(([A-Z]+)\)([^()]*)/', preg_replace('/\s+/', '', $value), $matches, PREG_SET_ORDER);
        $parts = [];
        foreach ($matches as [, $key, $part]) {
            $parts[$key] = $part !== '' ? $part : null;
        }

        return $scheme === 'TIBBICIHAZ'
            ? new self($scheme, $parts['UNO'] ?? null, $parts['LNO'] ?? null, $parts['SNO'] ?? null, self::date($parts['URT'] ?? null), null, $value)
            : new self($scheme, $parts['GTIN'] ?? null, $parts['BN'] ?? null, $parts['SN'] ?? null, null, self::date($parts['XD'] ?? null), $value);
    }

    /** YYAAGG → YYYY-AA-GG; geçersizse null. */
    private static function date(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1])) {
            return null;
        }

        return "20{$m[1]}-{$m[2]}-{$m[3]}";
    }
}

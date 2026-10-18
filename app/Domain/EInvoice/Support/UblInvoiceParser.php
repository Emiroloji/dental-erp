<?php

namespace App\Domain\EInvoice\Support;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * UBL-TR 1.2 fatura XML'ini okur. Entegratörden bağımsızdır: hangi
 * entegratörden gelirse gelsin GİB biçimi aynıdır. Harici varlıklar ve ağ
 * erişimi kapalıdır (XXE).
 */
class UblInvoiceParser
{
    private const NAMESPACES = [
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    public function parse(string $xml): ParsedInvoice
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->doctype !== null) {
            throw new EInvoiceException('Fatura belgesi okunamadı (geçerli bir UBL XML değil).');
        }

        $x = new DOMXPath($document);
        foreach (self::NAMESPACES as $prefix => $uri) {
            $x->registerNamespace($prefix, $uri);
        }

        $root = $document->documentElement;
        $uuid = $this->text($x, 'cbc:UUID', $root);
        $number = $this->text($x, 'cbc:ID', $root);
        $issueDate = $this->text($x, 'cbc:IssueDate', $root);

        if ($uuid === null || $number === null || $issueDate === null) {
            throw new EInvoiceException('Fatura belgesinde ETTN, fatura numarası ya da tarih yok.');
        }

        $party = $x->query('cac:AccountingSupplierParty/cac:Party', $root)->item(0);
        $totals = 'cac:LegalMonetaryTotal/cbc:';

        return new ParsedInvoice(
            uuid: $uuid,
            number: $number,
            profile: $this->text($x, 'cbc:ProfileID', $root),
            type: $this->text($x, 'cbc:InvoiceTypeCode', $root),
            issueDate: $issueDate,
            senderTaxNumber: $party ? ($this->text($x, 'cac:PartyIdentification/cbc:ID[@schemeID="VKN"]', $party) ?? $this->text($x, 'cac:PartyIdentification/cbc:ID[@schemeID="TCKN"]', $party)) : null,
            senderName: $party ? $this->partyName($x, $party) : null,
            currency: $this->text($x, 'cbc:DocumentCurrencyCode', $root) ?? 'TRY',
            lineExtension: $this->amount($x, $totals.'LineExtensionAmount', $root),
            taxExclusive: $this->amount($x, $totals.'TaxExclusiveAmount', $root),
            taxTotal: $this->amount($x, 'cac:TaxTotal/cbc:TaxAmount', $root),
            payable: $this->amount($x, $totals.'PayableAmount', $root),
            lines: array_map(fn (DOMElement $line) => $this->line($x, $line), iterator_to_array($x->query('cac:InvoiceLine', $root))),
        );
    }

    private function line(DOMXPath $x, DOMElement $line): ParsedLine
    {
        $quantity = $x->query('cbc:InvoicedQuantity', $line)->item(0);
        $identities = [];

        foreach ($x->query('cac:Item/cac:AdditionalItemIdentification/cbc:ID', $line) as $id) {
            if ($identity = TrackingIdentity::parse($id->getAttribute('schemeID'), $id->textContent)) {
                $identities[] = $identity;
            }
        }

        return new ParsedLine(
            lineNo: (int) $this->text($x, 'cbc:ID', $line),
            name: $this->text($x, 'cac:Item/cbc:Name', $line) ?? '',
            sellerCode: $this->text($x, 'cac:Item/cac:SellersItemIdentification/cbc:ID', $line),
            quantity: $this->decimal($quantity?->textContent ?? '0'),
            unitCode: $quantity?->getAttribute('unitCode') ?: null,
            unitPrice: $this->amount($x, 'cac:Price/cbc:PriceAmount', $line),
            lineAmount: $this->amount($x, 'cbc:LineExtensionAmount', $line),
            vatRate: ($rate = $this->text($x, 'cac:TaxTotal/cac:TaxSubtotal/cbc:Percent', $line)) !== null ? $this->decimal($rate) : null,
            identities: $identities,
        );
    }

    private function partyName(DOMXPath $x, DOMElement $party): ?string
    {
        if ($name = $this->text($x, 'cac:PartyName/cbc:Name', $party)) {
            return $name;
        }

        $person = trim(($this->text($x, 'cac:Person/cbc:FirstName', $party) ?? '').' '.($this->text($x, 'cac:Person/cbc:FamilyName', $party) ?? ''));

        return $person !== '' ? $person : null;
    }

    private function text(DOMXPath $x, string $path, DOMElement $context): ?string
    {
        $value = trim((string) $x->query($path, $context)->item(0)?->textContent);

        return $value !== '' ? $value : null;
    }

    private function amount(DOMXPath $x, string $path, DOMElement $context): int
    {
        $value = $this->text($x, $path, $context);

        return $value === null ? 0 : UblAmount::toKurus($value);
    }

    /** "2.000" → "2", "10.00" → "10", "2.50" → "2.5". */
    private function decimal(string $value): string
    {
        $value = trim($value);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}

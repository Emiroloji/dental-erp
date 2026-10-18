<?php

namespace Tests\Unit\EInvoice;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Support\TrackingIdentity;
use App\Domain\EInvoice\Support\UblAmount;
use App\Domain\EInvoice\Support\UblInvoiceParser;
use PHPUnit\Framework\TestCase;

class UblInvoiceParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/einvoice/'.$name);
    }

    public function test_amounts_are_converted_to_kurus_with_dot_as_decimal_separator(): void
    {
        $this->assertSame(19000000, UblAmount::toKurus('190000.000'));
        $this->assertSame(19000, UblAmount::toKurus('190.000'));
        $this->assertSame(12346, UblAmount::toKurus('123.455'));
        $this->assertSame(15000, UblAmount::toKurus('150'));
        $this->assertSame(4938, UblAmount::toKurus('49.382'));
        $this->assertSame(-1050, UblAmount::toKurus('-10.5'));
    }

    public function test_medical_device_tracking_identity(): void
    {
        $identity = TrackingIdentity::parse('TIBBICIHAZ', '(UNO)08699999999994(LNO)IMP2026A(SNO)SN-778812(URT)260115');

        $this->assertSame('08699999999994', $identity->gtin);
        $this->assertSame('IMP2026A', $identity->lot);
        $this->assertSame('SN-778812', $identity->serial);
        $this->assertSame('2026-01-15', $identity->productionDate);
        $this->assertNull($identity->expiryDate);
    }

    public function test_medicine_tracking_identity(): void
    {
        $identity = TrackingIdentity::parse('ILAC', '(GTIN)8680222690047(BN)0714450(SN)9546433(XD)270909');

        $this->assertSame('8680222690047', $identity->gtin);
        $this->assertSame('0714450', $identity->lot);
        $this->assertSame('9546433', $identity->serial);
        $this->assertSame('2027-09-09', $identity->expiryDate);
    }

    public function test_other_scheme_is_not_a_tracking_identity(): void
    {
        $this->assertNull(TrackingIdentity::parse('DIGER', '1111111111'));
    }

    public function test_medical_device_invoice(): void
    {
        $invoice = (new UblInvoiceParser)->parse($this->fixture('tibbi-cihaz.xml'));

        $this->assertSame('5d59e861-0ecc-11e7-a708-f52ed20ddd29', $invoice->uuid);
        $this->assertSame('ADM2026000451', $invoice->number);
        $this->assertSame('ILAC_TIBBICIHAZ', $invoice->profile);
        $this->assertSame('SATIS', $invoice->type);
        $this->assertSame('2026-10-17', $invoice->issueDate);
        $this->assertSame('1234567890', $invoice->senderTaxNumber);
        $this->assertSame('Anadolu Dental Medikal Ltd. Şti.', $invoice->senderName);
        $this->assertSame('TRY', $invoice->currency);
        $this->assertSame(2155000, $invoice->lineExtension);
        $this->assertSame(2155000, $invoice->taxExclusive);
        $this->assertSame(215500, $invoice->taxTotal);
        $this->assertSame(2370500, $invoice->payable);
        $this->assertCount(3, $invoice->lines);

        $implant = $invoice->lines[0];
        $this->assertSame(1, $implant->lineNo);
        $this->assertSame('Straumann BLT Ø4.1 RC SLActive 10mm', $implant->name);
        $this->assertSame('STR-021.4510', $implant->sellerCode);
        $this->assertSame('2', $implant->quantity);
        $this->assertSame('C62', $implant->unitCode);
        $this->assertSame(950000, $implant->unitPrice);
        $this->assertSame(1900000, $implant->lineAmount);
        $this->assertSame('10', $implant->vatRate);
        $this->assertCount(2, $implant->identities);
        $this->assertSame(['SN-778812', 'SN-778813'], array_map(fn ($i) => $i->serial, $implant->identities));

        $this->assertNull($invoice->lines[1]->identities[0]->serial);
        $this->assertSame('BG-55', $invoice->lines[1]->identities[0]->lot);
        $this->assertSame([], $invoice->lines[2]->identities);
    }

    public function test_person_sender_and_fractional_quantity(): void
    {
        $invoice = (new UblInvoiceParser)->parse($this->fixture('temel-ilac.xml'));

        $this->assertSame('10000000146', $invoice->senderTaxNumber);
        $this->assertSame('Ayşe Ege', $invoice->senderName);
        $this->assertSame('2.5', $invoice->lines[0]->quantity);
        $this->assertSame('BX', $invoice->lines[0]->unitCode);
        $this->assertSame(13580, $invoice->payable);
        $this->assertSame('2027-09-09', $invoice->lines[0]->identities[0]->expiryDate);
    }

    public function test_broken_xml_is_rejected(): void
    {
        $this->expectException(EInvoiceException::class);

        (new UblInvoiceParser)->parse('<Invoice><cbc:ID>');
    }

    public function test_external_entities_are_not_resolved(): void
    {
        $xml = str_replace(
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>',
            $this->fixture('temel-ilac.xml'),
        );
        $xml = str_replace('<cbc:Name>Artikain anestezik</cbc:Name>', '<cbc:Name>&xxe;</cbc:Name>', $xml);

        try {
            $invoice = (new UblInvoiceParser)->parse($xml);
            $this->assertStringNotContainsString('root:', $invoice->lines[0]->name);
        } catch (EInvoiceException) {
            $this->assertTrue(true);
        }
    }

    public function test_document_without_uuid_is_rejected(): void
    {
        $this->expectException(EInvoiceException::class);

        (new UblInvoiceParser)->parse(str_replace('<cbc:UUID>a68b5aa1-f7d7-11e8-a3a9-f783d4ccac99</cbc:UUID>', '', $this->fixture('temel-ilac.xml')));
    }
}

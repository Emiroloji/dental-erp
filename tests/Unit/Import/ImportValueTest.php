<?php

namespace Tests\Unit\Import;

use App\Domain\Import\Support\ImportValue;
use PHPUnit\Framework\TestCase;

class ImportValueTest extends TestCase
{
    public function test_number_accepts_turkish_and_dotted_formats(): void
    {
        $this->assertSame('1250.50', ImportValue::number('1.250,50'));
        $this->assertSame('12.5', ImportValue::number('12,5'));
        $this->assertSame('1250.50', ImportValue::number('1250.50'));
        $this->assertSame('40', ImportValue::number(' 40 '));
        $this->assertSame('', ImportValue::number(''));
        $this->assertSame('on iki', ImportValue::number('on iki'));
    }

    public function test_boolean_understands_evet_hayir(): void
    {
        $this->assertTrue(ImportValue::boolean('E'));
        $this->assertTrue(ImportValue::boolean('evet'));
        $this->assertTrue(ImportValue::boolean('1'));
        $this->assertFalse(ImportValue::boolean(''));
        $this->assertFalse(ImportValue::boolean('Hayır'));
        $this->assertFalse(ImportValue::boolean('h'));
        $this->assertNull(ImportValue::boolean('belki'));
    }

    public function test_date_accepts_turkish_iso_and_excel_serial(): void
    {
        $this->assertSame('2027-03-31', ImportValue::date('31.03.2027'));
        $this->assertSame('2027-03-31', ImportValue::date('2027-03-31'));
        $this->assertSame('2027-03-31', ImportValue::date('46477'));
        $this->assertSame('', ImportValue::date(''));
        $this->assertNull(ImportValue::date('31.02.2027'));
        $this->assertNull(ImportValue::date('yarın'));
    }

    public function test_key_ignores_case_and_surrounding_space(): void
    {
        $this->assertSame(ImportValue::key(' ENJ-01 '), ImportValue::key('enj-01'));
    }
}

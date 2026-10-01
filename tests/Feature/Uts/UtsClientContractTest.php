<?php

namespace Tests\Feature\Uts;

use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Clients\UnconfiguredUtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\UtsItem;
use Tests\TestCase;

class UtsClientContractTest extends TestCase
{
    public function test_pending_receipt_reads_uts_json_codes(): void
    {
        $receipt = PendingReceipt::fromArray([
            'GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'L1', 'SNO' => 'S1', 'ADT' => 3,
            'BID' => 'abc-1', 'BNO' => 'IRS-9', 'BZA' => '2026-10-01 09:30:00',
            'GKU' => 'Dental Tedarik A.Ş.', 'MME' => 'Marka Model',
        ]);

        $this->assertSame(7, $receipt->senderCode);
        $this->assertSame('08699999999994', $receipt->uno);
        $this->assertSame('L1', $receipt->lot);
        $this->assertSame('S1', $receipt->serial);
        $this->assertSame(3, $receipt->quantity);
        $this->assertSame('IRS-9', $receipt->documentNo);
        $this->assertSame('Dental Tedarik A.Ş.', $receipt->senderName);
    }

    public function test_item_reads_uts_json_codes(): void
    {
        $item = UtsItem::fromArray([
            'UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 5,
            'SKT' => '2028-01-31', 'UAK' => 'LOT', 'UDI' => 'UDI-1', 'MME' => 'Marka Model',
        ]);

        $this->assertSame('TIBBI_CIHAZ', $item->productType);
        $this->assertSame('2028-01-31', $item->expiryDate);
        $this->assertSame('LOT', $item->tracking);
    }

    public function test_fake_client_returns_configured_data_and_pages(): void
    {
        $rows = array_map(fn ($n) => PendingReceipt::fromArray(['UNO' => "U{$n}"]), range(1, 12));
        $client = new FakeUtsClient(pending: $rows);

        $first = $client->pendingReceipts(null, 0);
        $second = $client->pendingReceipts(null, 1);

        $this->assertCount(10, $first->items);
        $this->assertTrue($first->hasMore);
        $this->assertCount(2, $second->items);
        $this->assertFalse($second->hasMore);
    }

    public function test_fake_client_can_fail(): void
    {
        $this->expectException(UtsException::class);

        (new FakeUtsClient(failure: new UtsException('ÜTS şu an yanıt vermiyor.')))->lookup('1');
    }

    public function test_unconfigured_client_explains_missing_token(): void
    {
        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('ÜTS bağlantısı kurulmamış');

        (new UnconfiguredUtsClient)->pendingReceipts();
    }
}

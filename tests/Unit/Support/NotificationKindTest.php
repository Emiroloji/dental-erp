<?php

namespace Tests\Unit\Support;

use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Support\Notifications\NotificationKind;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationKindTest extends TestCase
{
    private function notification(string $type, array $data): DatabaseNotification
    {
        return new DatabaseNotification(['type' => $type, 'data' => $data]);
    }

    public function test_kind_is_resolved_from_type_or_data(): void
    {
        $this->assertSame('stock', NotificationKind::of($this->notification(StockLevelAlert::class, ['product_name' => 'X'])));
        $this->assertSame('cold_chain', NotificationKind::of($this->notification('App\\Any', ['kind' => 'cold_chain'])));
        $this->assertSame('other', NotificationKind::of($this->notification('App\\Any', [])));
        $this->assertSame('Soğuk Zincir', NotificationKind::label('cold_chain'));
        $this->assertSame('Diğer', NotificationKind::label('bilinmeyen'));
    }

    public function test_summary_uses_message_or_stock_reason(): void
    {
        $this->assertSame('Transfer onaylandı.', NotificationKind::summary($this->notification('App\\Any', ['message' => 'Transfer onaylandı.'])));
        $this->assertSame('Kompozit: Stok tamamen tükendi.', NotificationKind::summary($this->notification(StockLevelAlert::class, ['product_name' => 'Kompozit', 'reasons' => ['Stok tamamen tükendi.']])));
        $this->assertSame('Kompozit', NotificationKind::summary($this->notification(StockLevelAlert::class, ['product_name' => 'Kompozit', 'reasons' => []])));
    }
}

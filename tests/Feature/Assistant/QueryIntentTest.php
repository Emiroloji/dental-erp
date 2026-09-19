<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Support\AssistantReport;
use App\Domain\Assistant\Support\InterpreterContext;
use App\Domain\Assistant\Support\QueryIntent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Yapay zekâ cevabının doğrulanması: sağlayıcıya güvenilmez.
 */
class QueryIntentTest extends TestCase
{
    private InterpreterContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-18 12:00:00');

        $this->context = new InterpreterContext(
            today: Carbon::parse('2026-09-18'),
            branches: [['id' => 1, 'name' => 'Merkez']],
            warehouses: [['id' => 10, 'name' => 'Merkez — Depo']],
            categories: [['id' => 5, 'name' => 'Kompozitler']],
            suppliers: [],
        );
    }

    public function test_valid_answer_is_accepted(): void
    {
        $intent = QueryIntent::fromInterpreter(['understood' => true, 'report' => 'usage', 'from' => '2026-08-01', 'to' => '2026-08-31', 'branch_id' => 1, 'category_id' => '5', 'limit' => 5], $this->context);

        $this->assertSame(AssistantReport::Usage, $intent->report);
        $this->assertSame('2026-08-01 00:00:00', $intent->from->toDateTimeString());
        $this->assertSame('2026-08-31 23:59:59', $intent->to->toDateTimeString());
        $this->assertSame(1, $intent->branchId);
        $this->assertSame(5, $intent->categoryId);
        $this->assertSame(5, $intent->limit);
        $this->assertSame([], $intent->warnings);
    }

    public function test_missing_dates_default_to_this_month_and_reversed_dates_are_swapped(): void
    {
        $default = QueryIntent::fromInterpreter(['understood' => true, 'report' => 'movements'], $this->context);
        $this->assertSame(['2026-09-01', '2026-09-18'], [$default->from->toDateString(), $default->to->toDateString()]);

        $reversed = QueryIntent::fromInterpreter(['understood' => true, 'report' => 'movements', 'from' => '2026-09-10', 'to' => '2026-09-01'], $this->context);
        $this->assertSame(['2026-09-01', '2026-09-10'], [$reversed->from->toDateString(), $reversed->to->toDateString()]);
    }

    public function test_malformed_values_are_dropped_with_warnings(): void
    {
        $intent = QueryIntent::fromInterpreter([
            'understood' => true,
            'report' => 'usage',
            'from' => 'geçen ay',
            'branch_id' => 2,
            'warehouse_id' => 'x',
            'movement_type' => 'in',
            'risk' => 'critical',
            'limit' => 5000,
        ], $this->context);

        $this->assertNull($intent->branchId);
        $this->assertNull($intent->warehouseId);
        $this->assertCount(3, $intent->warnings);
        $this->assertSame('2026-09-01', $intent->from->toDateString());
        // Rapora ait olmayan alanlar yok sayılır, limit üst sınıra çekilir.
        $this->assertNull($intent->movementType);
        $this->assertNull($intent->risk);
        $this->assertSame(50, $intent->limit);
    }

    public function test_dates_are_ignored_for_point_in_time_reports(): void
    {
        $intent = QueryIntent::fromInterpreter(['understood' => true, 'report' => 'stock', 'from' => '2026-01-01', 'to' => '2026-01-31'], $this->context);

        $this->assertNull($intent->from);
        $this->assertNull($intent->to);
    }

    public function test_unknown_report_or_not_understood_yields_clarification(): void
    {
        $this->assertFalse(QueryIntent::fromInterpreter(['understood' => true, 'report' => 'hasta_listesi'], $this->context)->understood);

        $intent = QueryIntent::fromInterpreter(['understood' => false], $this->context);
        $this->assertFalse($intent->understood);
        $this->assertStringContainsString('Soruyu bir rapora çeviremedim', $intent->clarification);
    }
}

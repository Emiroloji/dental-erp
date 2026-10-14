<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use PHPUnit\Framework\TestCase;

class FinanceRuleExceptionTest extends TestCase
{
    public function test_field_hint_is_readable(): void
    {
        $e = new FinanceRuleException('x', 'party');

        $this->assertSame('party', $e->field);
        $this->assertSame('x', $e->getMessage());
    }

    public function test_message_without_field_is_a_general_error(): void
    {
        $this->assertNull((new FinanceRuleException('x'))->field);
    }
}

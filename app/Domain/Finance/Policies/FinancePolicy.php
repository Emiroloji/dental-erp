<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

/**
 * Finans organizasyon genelidir (karar 2.9): şube kapsamı yoktur, bu yüzden
 * personele yalnızca "tüm şubeler" kapsamıyla verilebilir (StaffService).
 * İptal kaydı silme değil düzeltmedir; ekranlar iptal için finance.update ister.
 */
class FinancePolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::Finance;
    }
}

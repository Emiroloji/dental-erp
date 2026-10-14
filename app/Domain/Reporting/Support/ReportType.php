<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Access\Support\Module;

/**
 * Kuyruk üzerinden dışa aktarılabilen raporlar (proje.md Bölüm 11).
 */
enum ReportType: string
{
    case Stock = 'stock';
    case Movements = 'movements';
    case Usage = 'usage';
    case Purchasing = 'purchasing';
    case Controlled = 'controlled';
    case FinanceIncomeExpense = 'finance_income_expense';
    case FinanceCashFlow = 'finance_cash_flow';
    case FinanceAging = 'finance_aging';
    case FinanceStatement = 'finance_statement';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stok Durumu Raporu',
            self::Movements => 'Stok Hareket Raporu',
            self::Usage => 'Kullanım ve Maliyet Raporu',
            self::Purchasing => 'Satın Alma ve İade Raporu',
            self::Controlled => 'Kontrollü Ürün Defteri',
            self::FinanceIncomeExpense => 'Gelir-Gider Raporu',
            self::FinanceCashFlow => 'Nakit Akışı Raporu',
            self::FinanceAging => 'Cari Yaşlandırma Raporu',
            self::FinanceStatement => 'Hesap Ekstresi',
        };
    }

    public function fileBaseName(): string
    {
        return match ($this) {
            self::Stock => 'stok-raporu',
            self::Movements => 'stok-hareket-raporu',
            self::Usage => 'kullanim-maliyet-raporu',
            self::Purchasing => 'satin-alma-iade-raporu',
            self::Controlled => 'kontrollu-urun-defteri',
            self::FinanceIncomeExpense => 'gelir-gider-raporu',
            self::FinanceCashFlow => 'nakit-akisi-raporu',
            self::FinanceAging => 'cari-yaslandirma-raporu',
            self::FinanceStatement => 'hesap-ekstresi',
        };
    }

    public function isFinance(): bool
    {
        return $this->module() === Module::Finance;
    }

    /**
     * Raporu isteyen kullanıcıda aranan okuma yetkisinin modülü.
     */
    public function module(): Module
    {
        return match ($this) {
            self::FinanceIncomeExpense, self::FinanceCashFlow, self::FinanceAging, self::FinanceStatement => Module::Finance,
            default => Module::Reports,
        };
    }

    /**
     * Hata bildiriminin gösterdiği, raporun asıl ekranı.
     */
    public function pageRoute(): string
    {
        return $this->isFinance() ? 'finance.reports' : 'reports.stock';
    }
}

<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Support\CategoryDirection;

class FinanceCategoryService
{
    /**
     * Önerilen başlangıç kategorileri. Organizasyon açılırken eklenmez (Platform
     * domain'i klinik verisine dokunmaz); kullanıcı Kategoriler ekranından ekler.
     *
     * @var array<int, array{0: string, 1: CategoryDirection}>
     */
    public const SUGGESTED = [
        ['Hasılat', CategoryDirection::Income],
        ['Kurum Tahsilatı', CategoryDirection::Income],
        ['Diğer Gelir', CategoryDirection::Income],
        ['Kira', CategoryDirection::Expense],
        ['Maaş', CategoryDirection::Expense],
        ['Malzeme', CategoryDirection::Expense],
        ['Fatura (elektrik/su/internet)', CategoryDirection::Expense],
        ['Vergi/SGK', CategoryDirection::Expense],
        ['Banka/POS Komisyonu', CategoryDirection::Expense],
        ['Diğer Gider', CategoryDirection::Expense],
    ];

    public function create(string $name, CategoryDirection $direction): FinanceCategory
    {
        $name = trim($name);

        if (FinanceCategory::where('direction', $direction)->where('name', $name)->exists()) {
            throw new FinanceRuleException("\"{$name}\" adında bir ".mb_strtolower($direction->label()).' kategorisi zaten var.');
        }

        return FinanceCategory::create(['name' => $name, 'direction' => $direction]);
    }

    public function deactivate(FinanceCategory $category): FinanceCategory
    {
        $category->update(['status' => 'passive']);

        return $category;
    }

    public function addSuggested(): int
    {
        $added = 0;

        foreach (self::SUGGESTED as [$name, $direction]) {
            if (! FinanceCategory::where('direction', $direction)->where('name', $name)->exists()) {
                FinanceCategory::create(['name' => $name, 'direction' => $direction]);
                $added++;
            }
        }

        return $added;
    }
}

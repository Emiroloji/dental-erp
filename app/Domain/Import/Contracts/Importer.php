<?php

namespace App\Domain\Import\Contracts;

use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Models\User;

/**
 * Bir içe aktarma şablonu: sütunları, indirilebilir şablonu, yazmadan
 * doğrulaması ve tek transaction'da yazması.
 */
interface Importer
{
    /** @return array<int, string> şablon sütun başlıkları, sırasıyla */
    public function headings(): array;

    public function template(User $actor): ImportTemplateExport;

    public function templateFileName(): string;

    /**
     * Veritabanına yazmadan tüm satırları doğrular.
     *
     * @param  array<int, array<string, string>>  $rows  SpreadsheetReader çıktısı
     */
    public function validate(array $rows, User $actor): ImportResult;

    /**
     * Geçerli sonucu tek transaction'da yazar; yazılan satır sayısını döndürür.
     * Geçersiz sonuçla çağrılırsa LogicException fırlatır.
     */
    public function import(ImportResult $result, User $actor): int;
}

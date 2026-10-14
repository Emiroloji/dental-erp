<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\PartyKind;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cari kartı. Hasta açılmaz (karar 2.6/2.9); ekran "kurum veya firma" diye yönlendirir.
 */
class FinancePartyService
{
    /**
     * @param  array{name: string, kind: PartyKind, phone?: ?string, email?: ?string, tax_number?: ?string, default_term_days?: ?int, note?: ?string}  $data
     */
    public function create(array $data): FinanceParty
    {
        $attributes = $this->attributes($data);
        $this->ensureUniqueName($attributes['name']);

        try {
            return FinanceParty::create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new FinanceRuleException("\"{$attributes['name']}\" adında bir cari zaten var.", 'name');
        }
    }

    /**
     * @param  array{name: string, kind: PartyKind, phone?: ?string, email?: ?string, tax_number?: ?string, default_term_days?: ?int, note?: ?string}  $data
     */
    public function update(FinanceParty $party, array $data): FinanceParty
    {
        $attributes = $this->attributes($data);
        $this->ensureUniqueName($attributes['name'], $party);

        try {
            $party->update($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new FinanceRuleException("\"{$attributes['name']}\" adında bir cari zaten var.", 'name');
        }

        return $party;
    }

    public function deactivate(FinanceParty $party): FinanceParty
    {
        $party->update(['status' => 'passive']);

        return $party;
    }

    public function activate(FinanceParty $party): FinanceParty
    {
        $party->update(['status' => 'active']);

        return $party;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $name = trim($data['name']);

        if ($name === '') {
            throw new FinanceRuleException('Cari adı boş olamaz.', 'name');
        }

        return [
            'name' => $name,
            'kind' => $data['kind'],
            'phone' => $this->clean($data['phone'] ?? null),
            'email' => $this->clean($data['email'] ?? null),
            'tax_number' => $this->clean($data['tax_number'] ?? null),
            'default_term_days' => $data['default_term_days'] ?? null,
            'note' => $this->clean($data['note'] ?? null),
        ];
    }

    private function ensureUniqueName(string $name, ?FinanceParty $except = null): void
    {
        $existing = FinanceParty::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->first();

        if ($existing === null) {
            return;
        }

        throw new FinanceRuleException($existing->isActive()
            ? "\"{$existing->name}\" adında bir cari zaten var."
            : "\"{$existing->name}\" adında pasif bir cari var; Cariler listesinden aktifleştirin.", 'name');
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

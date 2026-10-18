<?php

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Exceptions\PatientException;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Patients\Support\IdentityType;
use App\Domain\Patients\Support\PatientIdentity;

class PatientService
{
    /**
     * @param  array{first_name: string, last_name: string, identity_type: string, identity_no?: ?string, birth_year?: ?int}  $data
     */
    public function create(array $data): Patient
    {
        return Patient::create($this->attributes($data));
    }

    /**
     * @param  array{first_name: string, last_name: string, identity_type: string, identity_no?: ?string, birth_year?: ?int}  $data
     */
    public function update(Patient $patient, array $data): Patient
    {
        $patient->update($this->attributes($data, $patient));

        return $patient;
    }

    /**
     * Kullanım kaydı olan hasta silinemez: ÜTS bildirimi ve implant geçmişi
     * ona bağlıdır.
     */
    public function delete(Patient $patient): void
    {
        if (PatientUsage::where('patient_id', $patient->id)->exists()) {
            throw new PatientException('Bu hastaya ürün kullanımı kayıtlı; silinemez.', 'patient');
        }

        $patient->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Patient $existing = null): array
    {
        $type = IdentityType::from($data['identity_type']);
        $number = $type->needsNumber() ? PatientIdentity::normalize((string) ($data['identity_no'] ?? '')) : null;

        if ($error = PatientIdentity::validationError($type, $number)) {
            throw new PatientException($error);
        }

        $hash = $number !== null ? PatientIdentity::hash($number) : null;

        if ($hash !== null && Patient::where('identity_hash', $hash)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            throw new PatientException('Bu kimlik numarasıyla kayıtlı bir hasta zaten var; aramadan bulup seçin.');
        }

        return [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'identity_type' => $type,
            'identity_no' => $number,
            'identity_hash' => $hash,
            'birth_year' => $data['birth_year'] ?? null,
        ];
    }
}

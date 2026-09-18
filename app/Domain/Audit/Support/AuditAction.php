<?php

namespace App\Domain\Audit\Support;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Oluşturuldu',
            self::Updated => 'Güncellendi',
            self::Deleted => 'Silindi',
        };
    }
}

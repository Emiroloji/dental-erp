<?php

namespace App\Domain\Reporting\Support;

enum ReportExportStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Hazırlanıyor',
            self::Completed => 'Hazır',
            self::Failed => 'Başarısız',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-status-warn-bg text-status-warn',
            self::Completed => 'bg-status-good-bg text-status-good',
            self::Failed => 'bg-status-critical-bg text-status-critical',
        };
    }
}

<?php

namespace App\Domain\Assistant\Support;

enum AssistantQueryStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case NotUnderstood = 'not_understood';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'İşleniyor',
            self::Answered => 'Cevaplandı',
            self::NotUnderstood => 'Anlaşılamadı',
            self::Failed => 'Hata',
        };
    }
}

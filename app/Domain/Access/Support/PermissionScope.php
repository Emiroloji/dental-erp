<?php

namespace App\Domain\Access\Support;

enum PermissionScope: string
{
    case OwnBranch = 'own_branch';
    case SelectedBranches = 'selected_branches';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::OwnBranch => 'Sadece kendi şubesi',
            self::SelectedBranches => 'Seçili şubeler',
            self::All => 'Tüm şubeler',
        };
    }
}

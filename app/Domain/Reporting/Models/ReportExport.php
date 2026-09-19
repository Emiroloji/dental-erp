<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ReportExport extends Model
{
    use BelongsToOrganization, Prunable;

    /** Dışa aktarım dosyaları bu kadar gün saklanır, sonra silinir. */
    public const RETENTION_DAYS = 7;

    protected $fillable = [
        'organization_id',
        'user_id',
        'report',
        'format',
        'parameters',
        'status',
        'file_name',
        'file_path',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'report' => ReportType::class,
        'status' => ReportExportStatus::class,
        'parameters' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    protected function pruning(): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }
    }
}

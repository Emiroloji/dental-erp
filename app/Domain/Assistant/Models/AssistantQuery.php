<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Assistant\Support\AssistantQueryStatus;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantQuery extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'question',
        'provider',
        'status',
        'intent',
        'error',
    ];

    protected $casts = [
        'status' => AssistantQueryStatus::class,
        'intent' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

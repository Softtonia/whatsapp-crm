<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'tenant_id',
        'contact_id',
        'owner_id',
        'pipeline_stage_id',
        'title',
        'status',
        'priority',
        'follow_up_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(
            LeadPipelineStage::class,
            'pipeline_stage_id'
        );
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }
}

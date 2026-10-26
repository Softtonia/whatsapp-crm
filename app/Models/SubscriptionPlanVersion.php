<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlanVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'version_number',
        'configuration',
        'change_summary',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'configuration' => 'array',
        'published_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'version_id');
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'version_id');
    }
}

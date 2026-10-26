<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'version_id',
        'feature_key',
        'feature_name',
        'feature_type',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanVersion::class, 'version_id');
    }
}

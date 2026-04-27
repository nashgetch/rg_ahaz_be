<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'provider_subscription_id',
        'event_type',
        'phone',
        'status',
        'package_id',
        'method_id',
        'trial_end',
        'activation_date',
        'expires_at',
        'active_on_date',
        'provider_reference',
        'amount_etb',
        'tokens_awarded',
        'starts_at',
        'ends_at',
        'paid_at',
        'raw_payload',
    ];

    protected $casts = [
        'amount_etb' => 'decimal:2',
        'tokens_awarded' => 'integer',
        'trial_end' => 'datetime',
        'activation_date' => 'datetime',
        'expires_at' => 'datetime',
        'active_on_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'paid_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

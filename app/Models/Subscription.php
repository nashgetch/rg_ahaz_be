<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'status',
        'provider_reference',
        'external_reference',
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

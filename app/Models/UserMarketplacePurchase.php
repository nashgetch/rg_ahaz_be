<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMarketplacePurchase extends Model
{
    protected $fillable = [
        'user_id',
        'marketplace_item_id',
        'token_cost',
        'etb_value',
        'status',
        'reference',
        'redemption_phone',
        'metadata',
        'purchased_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'etb_value' => 'decimal:2',
        'purchased_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MarketplaceItem::class, 'marketplace_item_id');
    }
}

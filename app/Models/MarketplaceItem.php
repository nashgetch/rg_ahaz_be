<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceItem extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'token_cost',
        'etb_value',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'etb_value' => 'decimal:2',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(UserMarketplacePurchase::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OTP extends Model
{
    protected $table = 'otps';

    protected $fillable = [
        'phone',
        'code',
        'type',
        'expires_at',
        'consumed_at',
        'attempts',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer'
    ];

    /**
     * Check if OTP is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if OTP has exceeded max attempts
     */
    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= 3;
    }

    /**
     * Check if OTP is consumed
     */
    public function isConsumed(): bool
    {
        return !is_null($this->consumed_at);
    }
}

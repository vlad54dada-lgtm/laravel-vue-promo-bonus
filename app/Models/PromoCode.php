<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    /** @use HasFactory<\Database\Factories\PromoCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'amount_cents',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(PromoClaim::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}

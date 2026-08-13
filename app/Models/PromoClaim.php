<?php

namespace App\Models;

use App\Enums\PromoClaimStatus;
use App\Enums\PromoRejectReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoClaim extends Model
{
    protected $fillable = [
        'user_id',
        'promo_code_id',
        'code_entered',
        'amount_cents',
        'status',
        'reject_reason',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => PromoClaimStatus::class,
            'reject_reason' => PromoRejectReason::class,
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }
}

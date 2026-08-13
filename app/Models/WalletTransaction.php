<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    // Append-only ledger: записи ніколи не оновлюються.
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'promo_claim_id',
        'amount_cents',
        'type',
        'balance_after_cents',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'type' => WalletTransactionType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoClaim(): BelongsTo
    {
        return $this->belongsTo(PromoClaim::class);
    }
}

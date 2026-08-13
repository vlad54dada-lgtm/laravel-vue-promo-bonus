<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PromoClaim
 */
class PromoClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code_entered,
            'status' => $this->status,
            'reject_reason' => $this->reject_reason,
            'amount_cents' => $this->amount_cents,
            'created_at' => $this->created_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
        ];
    }
}

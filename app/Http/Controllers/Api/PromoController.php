<?php

namespace App\Http\Controllers\Api;

use App\Enums\PromoClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimPromoRequest;
use App\Http\Resources\PromoClaimResource;
use App\Services\PromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PromoController extends Controller
{
    /**
     * POST /api/promo/claim — Тікет 1.
     * Гравець — тільки з токена; вся доменна логіка в PromoService.
     */
    public function claim(ClaimPromoRequest $request, PromoService $service): JsonResponse
    {
        $claim = $service->claim($request->user(), $request->validated('code'));

        return response()->json([
            'bonus_amount_cents' => $claim->amount_cents,
            'balance_cents' => $request->user()->refresh()->balance_cents,
            'claim' => PromoClaimResource::make($claim),
        ]);
    }

    /**
     * GET /api/promo/history — Тікет 1.
     * Пагінація + фільтр за статусом; тільки записи поточного гравця.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(PromoClaimStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // per_page обрізається до 50 (H4), а не відхиляється
        $perPage = min((int) ($validated['per_page'] ?? 10), 50);

        $claims = $request->user()->promoClaims()
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->latest('id')
            ->paginate($perPage);

        return PromoClaimResource::collection($claims);
    }
}

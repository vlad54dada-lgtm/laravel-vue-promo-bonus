<?php

namespace App\Services;

use App\Enums\PromoClaimStatus;
use App\Enums\PromoRejectReason;
use App\Enums\WalletTransactionType;
use App\Exceptions\PromoAlreadyUsedException;
use App\Exceptions\PromoExpiredException;
use App\Exceptions\PromoNotFoundException;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Єдина точка зміни балансу гравця.
 *
 * Інваріанти (docs/ARCHITECTURE.md):
 * - кожна зміна балансу — в транзакції і з рядком у wallet_transactions;
 * - порядок локів завжди «рядок гравця → рядки promo_claims»;
 * - перевірка «вже використаний» — тільки locking read (звичайний SELECT
 *   під InnoDB REPEATABLE READ читає снапшот і пропустить свіжий коміт);
 * - фінальна гарантія від подвійного нарахування — UNIQUE(user_id, promo_code_id).
 */
class PromoService
{
    /**
     * Нарахувати бонус за промокодом.
     *
     * @throws PromoNotFoundException
     * @throws PromoExpiredException
     * @throws PromoAlreadyUsedException
     */
    public function claim(User $user, string $rawCode): PromoClaim
    {
        $code = Str::upper($rawCode);

        $promo = PromoCode::query()->where('code', $code)->first();

        if ($promo === null) {
            $this->recordRejection($user, $code, PromoRejectReason::NotFound);
            throw new PromoNotFoundException;
        }

        if ($promo->isExpired()) {
            $this->recordRejection($user, $code, PromoRejectReason::Expired);
            throw new PromoExpiredException;
        }

        try {
            return DB::transaction(function () use ($user, $promo): PromoClaim {
                $lockedUser = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyUsed = PromoClaim::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->where('promo_code_id', $promo->getKey())
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyUsed) {
                    throw new PromoAlreadyUsedException;
                }

                $claim = PromoClaim::query()->create([
                    'user_id' => $lockedUser->getKey(),
                    'promo_code_id' => $promo->getKey(),
                    'code_entered' => $promo->code,
                    'amount_cents' => $promo->amount_cents,
                    'status' => PromoClaimStatus::Applied,
                ]);

                $this->applyBalanceChange(
                    $lockedUser,
                    $claim,
                    $promo->amount_cents,
                    WalletTransactionType::PromoClaim,
                );

                return $claim;
            });
        } catch (PromoAlreadyUsedException $e) {
            $this->recordRejection($user, $code, PromoRejectReason::AlreadyUsed);
            throw $e;
        } catch (UniqueConstraintViolationException) {
            // Гонка дійшла до INSERT попри локи — спрацював останній рубіж.
            $this->recordRejection($user, $code, PromoRejectReason::AlreadyUsed);
            throw new PromoAlreadyUsedException;
        }
    }

    /**
     * Атомарна зміна балансу з ledger-рядком. Викликається ТІЛЬКИ зсередини
     * транзакції з уже заблокованим рядком гравця.
     */
    private function applyBalanceChange(
        User $lockedUser,
        PromoClaim $claim,
        int $deltaCents,
        WalletTransactionType $type,
    ): void {
        $newBalance = $lockedUser->balance_cents + $deltaCents;

        if ($newBalance < 0) {
            // За поточних правил недосяжно (єдине списання — revoke раніше
            // нарахованого), але грошовий інваріант перевіряємо завжди.
            throw new \LogicException('Balance would go negative — invariant violated.');
        }

        $lockedUser->forceFill(['balance_cents' => $newBalance])->save();

        WalletTransaction::query()->create([
            'user_id' => $lockedUser->getKey(),
            'promo_claim_id' => $claim->getKey(),
            'amount_cents' => $deltaCents,
            'type' => $type,
            'balance_after_cents' => $newBalance,
        ]);
    }

    /**
     * Відхилена спроба персистується для історії (фільтр «відхилено» з ТЗ).
     * promo_code_id завжди NULL: UNIQUE(user_id, promo_code_id) обмежує лише
     * «споживаючі» записи, а rejected-спроб може бути скільки завгодно.
     */
    private function recordRejection(User $user, string $code, PromoRejectReason $reason): void
    {
        PromoClaim::query()->create([
            'user_id' => $user->getKey(),
            'promo_code_id' => null,
            'code_entered' => $code,
            'amount_cents' => null,
            'status' => PromoClaimStatus::Rejected,
            'reject_reason' => $reason,
        ]);
    }
}

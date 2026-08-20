<?php

namespace App\Services;

use App\Enums\PromoClaimStatus;
use App\Enums\PromoRejectReason;
use App\Enums\WalletTransactionType;
use App\Exceptions\ClaimAlreadyRevokedException;
use App\Exceptions\ClaimNotFoundException;
use App\Exceptions\ClaimNotRevocableException;
use App\Exceptions\PromoAlreadyUsedException;
use App\Exceptions\PromoCooldownException;
use App\Exceptions\PromoExpiredException;
use App\Exceptions\PromoNotFoundException;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
 * - фінальна гарантія від подвійного нарахування — UNIQUE(user_id, promo_code_id);
 * - кулдаун D7 («раз на N год») — теж locking read під локом гравця; DB-рубежа
 *   для ковзного вікна не існує, гонку закриває сам лок рядка users.
 */
class PromoService
{
    /**
     * Нарахувати бонус за промокодом.
     *
     * @throws PromoNotFoundException
     * @throws PromoExpiredException
     * @throws PromoAlreadyUsedException
     * @throws PromoCooldownException
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
            // attempts: 3 — Laravel ретраїть транзакцію на deadlock (InnoDB
            // gap-локи двох гравців на одному проміжку індексу) і SQLITE_BUSY;
            // доменні винятки не ретраяться, а прокидаються одразу.
            return DB::transaction(function () use ($user, $promo): PromoClaim {
                $lockedUser = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->hasConsumingClaim($lockedUser, $promo)) {
                    throw new PromoAlreadyUsedException;
                }

                // D7 — після already_used: постійна причина точніша за тимчасову
                $cooldownExpiresAt = $this->cooldownExpiresAt($lockedUser);

                if ($cooldownExpiresAt !== null) {
                    throw new PromoCooldownException($cooldownExpiresAt);
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
            }, attempts: 3);
        } catch (PromoAlreadyUsedException $e) {
            $this->recordRejection($user, $code, PromoRejectReason::AlreadyUsed);
            throw $e;
        } catch (PromoCooldownException $e) {
            $this->recordRejection($user, $code, PromoRejectReason::Cooldown);
            throw $e;
        } catch (UniqueConstraintViolationException) {
            // Гонка дійшла до INSERT попри локи — спрацював останній рубіж.
            $this->recordRejection($user, $code, PromoRejectReason::AlreadyUsed);
            throw new PromoAlreadyUsedException;
        }
    }

    /**
     * Скасувати раніше нараховане бонусне нарахування (Тікет 2).
     *
     * Повторний виклик на вже скасованому — 409, кошти не списуються вдруге.
     * Чужий або неіснуючий claim — 404 (існування чужих записів не розкриваємо).
     *
     * @throws ClaimNotFoundException
     * @throws ClaimAlreadyRevokedException
     * @throws ClaimNotRevocableException
     */
    public function revoke(User $user, int $claimId): PromoClaim
    {
        return DB::transaction(function () use ($user, $claimId): PromoClaim {
            // Той самий порядок локів, що й у claim: user → promo_claims
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $claim = PromoClaim::query()
                ->whereKey($claimId)
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                throw new ClaimNotFoundException;
            }

            if ($claim->status === PromoClaimStatus::Revoked) {
                throw new ClaimAlreadyRevokedException;
            }

            if ($claim->status !== PromoClaimStatus::Applied) {
                throw new ClaimNotRevocableException;
            }

            // СУБД-незалежний рубіж від подвійного списання: умовний UPDATE.
            // 0 affected rows = статус уже змінив конкурент → нічого не списуємо.
            $updated = PromoClaim::query()
                ->whereKey($claim->getKey())
                ->where('status', PromoClaimStatus::Applied->value)
                ->update([
                    'status' => PromoClaimStatus::Revoked->value,
                    'revoked_at' => now(),
                ]);

            if ($updated === 0) {
                throw new ClaimAlreadyRevokedException;
            }

            $this->applyBalanceChange(
                $lockedUser,
                $claim,
                -$claim->amount_cents,
                WalletTransactionType::PromoRevoke,
            );

            return $claim->refresh();
        }, attempts: 3);
    }

    /**
     * Locking read перевірки «гравець уже споживав цей код» (applied|revoked).
     * Саме locking: звичайний SELECT під InnoDB REPEATABLE READ читає снапшот
     * і не побачить claim, закомічений паралельною транзакцією.
     */
    protected function hasConsumingClaim(User $lockedUser, PromoCode $promo): bool
    {
        return PromoClaim::query()
            ->where('user_id', $lockedUser->getKey())
            ->where('promo_code_id', $promo->getKey())
            ->lockForUpdate()
            ->exists();
    }

    /**
     * D7: коли спливає кулдаун гравця, або null, якщо його немає.
     *
     * Locking read останнього «споживаючого» claim (promo_code_id IS NOT NULL —
     * rejected-спроби кулдаун не запускають) у ковзному вікні. Викликається
     * тільки під локом рядка гравця: UNIQUE-індексом ковзне вікно не виразиш,
     * тож єдина гарантія від гонки — серіалізація claim-ів гравця цим локом.
     */
    protected function cooldownExpiresAt(User $lockedUser): ?Carbon
    {
        $hours = (int) config('promo.cooldown_hours');

        if ($hours <= 0) {
            return null; // кулдаун вимкнено (демо/локальні сценарії)
        }

        $lastConsuming = PromoClaim::query()
            ->where('user_id', $lockedUser->getKey())
            ->whereNotNull('promo_code_id')
            ->where('created_at', '>', now()->subHours($hours))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        return $lastConsuming?->created_at->addHours($hours);
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

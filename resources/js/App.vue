<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import api, { clearToken, hasToken } from './api';
import { money } from './format';
import AppIcon from './components/AppIcon.vue';
import LoginForm from './components/LoginForm.vue';
import PromoClaimForm from './components/PromoClaimForm.vue';
import PromoHistory from './components/PromoHistory.vue';

const user = ref(null);
const booting = ref(true);
const history = ref(null);

// Баланс — головна цифра екрана: зміна анімується count-up'ом (400ms,
// easeOutCubic), щоб око зловило сам факт зміни. Reduced motion → одразу.
const displayBalance = ref(0);
let balanceRaf = null;

function animateBalance(to) {
    const from = displayBalance.value;
    cancelAnimationFrame(balanceRaf);

    if (from === to || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        displayBalance.value = to;
        return;
    }

    const start = performance.now();
    const duration = 400;
    const tick = (now) => {
        const progress = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        displayBalance.value = Math.round(from + (to - from) * eased);
        if (progress < 1) {
            balanceRaf = requestAnimationFrame(tick);
        }
    };
    balanceRaf = requestAnimationFrame(tick);
}

watch(
    () => user.value?.balance_cents,
    (value) => {
        if (typeof value === 'number') {
            animateBalance(value);
        }
    },
);

async function fetchMe() {
    try {
        const { data } = await api.get('/me');
        displayBalance.value = data.balance_cents; // перший рендер без анімації
        user.value = data;
    } catch {
        // 401 обробить інтерцептор (auth:logout)
    }
}

function onLoggedIn(loggedInUser) {
    displayBalance.value = loggedInUser.balance_cents;
    user.value = loggedInUser;
}

function onLogout() {
    user.value = null;
}

async function logout() {
    try {
        await api.post('/auth/logout'); // відкликаємо токен на сервері
    } catch {
        // токен уже недійсний — просто чистимо клієнт
    }
    clearToken();
    user.value = null;
}

/**
 * Після claim: оновлюємо баланс (з відповіді claim) та перезавантажуємо
 * історію. Якщо balance === null — claim відхилено, баланс не змінився,
 * але в історії з'явився rejected-запис.
 */
function onClaimed(balanceCents) {
    if (balanceCents !== null && user.value) {
        user.value.balance_cents = balanceCents;
    }
    history.value?.reload();
}

/** Після revoke: баланс приходить у відповіді PATCH-запиту. */
function onBalanceChanged(balanceCents) {
    if (user.value && typeof balanceCents === 'number') {
        user.value.balance_cents = balanceCents;
    } else {
        fetchMe();
    }
}

onMounted(async () => {
    window.addEventListener('auth:logout', onLogout);
    if (hasToken()) {
        await fetchMe();
    }
    booting.value = false;
});

onUnmounted(() => {
    window.removeEventListener('auth:logout', onLogout);
    cancelAnimationFrame(balanceRaf);
});
</script>

<template>
    <div class="min-h-dvh bg-canvas text-ink">
        <!-- boot поза Transition: видимість початкового контенту ніколи не
             гейтиться анімацією (фонові вкладки не композитять кадри) -->
        <div v-if="booting" />

        <Transition v-else name="screen" mode="out-in">
            <LoginForm v-if="!user" key="login" @logged-in="onLoggedIn" />

            <div v-else key="app" class="mx-auto max-w-2xl px-4 py-5 sm:py-8">
                <header class="mb-6 flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <AppIcon name="ticket" class="size-5 shrink-0 text-ink" />
                        <span class="truncate text-[15px] font-bold tracking-tight">Promo Bonus</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Баланс: біла піл-капсула — головна цифра екрана -->
                        <span
                            class="inline-flex min-h-10 items-center rounded-full bg-ink px-4 text-[15px] font-bold text-canvas tabular-nums"
                            :aria-label="`Баланс: ${money(displayBalance)}`"
                        >
                            {{ money(displayBalance) }}
                        </span>
                        <button
                            type="button"
                            class="pressable focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border border-line px-4 text-sm font-medium text-ink-soft transition-colors duration-150 hover:border-ink-faint hover:text-ink"
                            @click="logout"
                        >
                            <AppIcon name="log-out" class="size-4" />
                            <span class="hidden sm:inline">Вийти</span>
                        </button>
                    </div>
                </header>

                <main class="space-y-4">
                    <PromoClaimForm @claimed="onClaimed" />
                    <PromoHistory ref="history" @balance-changed="onBalanceChanged" />
                </main>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import api, { clearToken, hasToken } from './api';
import { money } from './format';
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

            <div v-else key="app" class="mx-auto max-w-2xl px-4 py-6 sm:py-10">
                <header class="mb-8 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-ink-faint">{{ user.name }}</p>
                        <p class="mt-1 flex items-baseline gap-2">
                            <span class="text-[2rem] leading-none font-bold tracking-tight tabular-nums">
                                {{ money(displayBalance) }}
                            </span>
                            <span class="text-sm font-medium text-ink-soft">баланс</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="pressable focus-ring min-h-11 rounded-lg border border-line px-4 text-sm font-medium text-ink-soft transition-colors duration-150 hover:border-line hover:bg-card hover:text-ink"
                        @click="logout"
                    >
                        Вийти
                    </button>
                </header>

                <main class="space-y-4">
                    <PromoClaimForm @claimed="onClaimed" />
                    <PromoHistory ref="history" @balance-changed="onBalanceChanged" />
                </main>
            </div>
        </Transition>
    </div>
</template>

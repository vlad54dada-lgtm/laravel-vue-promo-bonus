<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import api, { clearToken, hasToken } from './api';
import { money } from './format';
import LoginForm from './components/LoginForm.vue';
import PromoClaimForm from './components/PromoClaimForm.vue';
import PromoHistory from './components/PromoHistory.vue';

const user = ref(null);
const booting = ref(true);
const history = ref(null);

async function fetchMe() {
    try {
        const { data } = await api.get('/me');
        user.value = data;
    } catch {
        // 401 обробить інтерцептор (auth:logout)
    }
}

function onLoggedIn(loggedInUser) {
    user.value = loggedInUser;
}

function onLogout() {
    user.value = null;
}

function logout() {
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

onUnmounted(() => window.removeEventListener('auth:logout', onLogout));
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <template v-if="booting" />

        <LoginForm v-else-if="!user" @logged-in="onLoggedIn" />

        <div v-else class="mx-auto max-w-2xl px-4 py-8">
            <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500">{{ user.name }}</p>
                    <p class="text-2xl font-bold tabular-nums">
                        Баланс: {{ money(user.balance_cents) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-500 transition hover:bg-white hover:text-slate-700"
                    @click="logout"
                >
                    Вийти
                </button>
            </header>

            <main class="space-y-6">
                <PromoClaimForm @claimed="onClaimed" />
                <PromoHistory ref="history" @balance-changed="onBalanceChanged" />
            </main>
        </div>
    </div>
</template>

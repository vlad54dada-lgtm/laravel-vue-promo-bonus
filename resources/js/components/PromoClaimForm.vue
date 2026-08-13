<script setup>
import { computed, ref } from 'vue';
import api, { errorMessage } from '../api';
import { money } from '../format';

const emit = defineEmits(['claimed']);

const code = ref('');
// Стани форми з ТЗ: idle → loading → success | error
const state = ref('idle');
const error = ref(null);
const lastBonus = ref(null);
const lastBalance = ref(null);

const CODE_PATTERN = /^[A-Za-z0-9]{6,12}$/;

const clientHint = computed(() => {
    if (code.value === '' || CODE_PATTERN.test(code.value)) {
        return null;
    }
    return 'Промокод: 6–12 символів, лише латинські літери та цифри.';
});

const loading = computed(() => state.value === 'loading');

async function submit() {
    if (loading.value) {
        return; // подвійний сабміт неможливий
    }

    state.value = 'loading';
    error.value = null;
    lastBonus.value = null;
    lastBalance.value = null;

    try {
        const { data } = await api.post('/promo/claim', { code: code.value });
        state.value = 'success';
        lastBonus.value = data.bonus_amount_cents;
        lastBalance.value = data.balance_cents;
        code.value = '';
        emit('claimed', data.balance_cents);
    } catch (e) {
        state.value = 'error';
        error.value = errorMessage(e);
        emit('claimed', null); // історія оновлюється і при відмові — там з'явився rejected-запис
    }
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-900">Застосувати промокод</h2>

        <form class="mt-4 flex flex-col gap-3 sm:flex-row" @submit.prevent="submit">
            <label for="promo-code" class="sr-only">Промокод</label>
            <input
                id="promo-code"
                v-model.trim="code"
                type="text"
                placeholder="Наприклад, WELCOME50"
                maxlength="12"
                :disabled="loading"
                :aria-invalid="clientHint ? 'true' : undefined"
                :aria-describedby="clientHint ? 'promo-code-hint' : undefined"
                class="flex-1 rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm uppercase tracking-wider focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:bg-slate-50"
            />
            <button
                type="submit"
                :disabled="loading || !code"
                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span v-if="loading" class="inline-flex items-center gap-2">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                    Перевіряємо…
                </span>
                <span v-else>Застосувати</span>
            </button>
        </form>

        <p v-if="clientHint" id="promo-code-hint" aria-live="polite" class="mt-2 text-sm text-amber-600">{{ clientHint }}</p>

        <p
            v-if="state === 'success'"
            role="status"
            class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700"
        >
            Бонус <strong>{{ money(lastBonus) }}</strong> нараховано 🎉
            Новий баланс: <strong>{{ money(lastBalance) }}</strong>
        </p>

        <p
            v-if="state === 'error'"
            role="alert"
            class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        >
            {{ error }}
        </p>
    </section>
</template>

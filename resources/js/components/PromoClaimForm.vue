<script setup>
import { computed, onUnmounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import { money } from '../format';

const emit = defineEmits(['claimed']);

const code = ref('');
// Стани форми з ТЗ: idle → loading → success | error
const state = ref('idle');
const error = ref(null);
const lastBonus = ref(null);
const lastBalance = ref(null);

// Success зникає сам (5s): цифра балансу в повідомленні не повинна
// пережити наступні операції і почати суперечити шапці.
// Error лишається, доки гравець не почне виправляти — причину треба встигнути прочитати.
let successTimer = null;

function scheduleSuccessDismiss() {
    clearTimeout(successTimer);
    successTimer = setTimeout(() => {
        if (state.value === 'success') {
            state.value = 'idle';
        }
    }, 5000);
}

onUnmounted(() => clearTimeout(successTimer));

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
        scheduleSuccessDismiss();
        emit('claimed', data.balance_cents);
    } catch (e) {
        state.value = 'error';
        error.value = errorMessage(e);
        emit('claimed', null); // історія оновлюється і при відмові — там з'явився rejected-запис
    }
}
</script>

<template>
    <section class="rounded-2xl border border-line-soft bg-card p-5 sm:p-6">
        <h2 class="text-base font-semibold tracking-tight">Застосувати промокод</h2>

        <form class="mt-4 flex flex-col gap-2.5 sm:flex-row" @submit.prevent="submit">
            <label for="promo-code" class="sr-only">Промокод</label>
            <input
                id="promo-code"
                v-model.trim="code"
                type="text"
                placeholder="Наприклад, WELCOME50"
                maxlength="12"
                autocomplete="off"
                spellcheck="false"
                :disabled="loading"
                :aria-invalid="clientHint ? 'true' : undefined"
                :aria-describedby="clientHint ? 'promo-code-hint' : undefined"
                class="focus-ring min-h-11 flex-1 rounded-lg border border-line bg-canvas px-3.5 font-mono text-sm tracking-[0.08em] text-ink uppercase transition-colors duration-150 placeholder:normal-case placeholder:font-sans placeholder:tracking-normal placeholder:text-ink-faint focus:border-mint disabled:opacity-60"
            />
            <button
                type="submit"
                :disabled="loading || !code"
                class="pressable focus-ring inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-mint px-6 text-sm font-semibold text-mint-ink transition-[background-color,opacity] duration-150 hover:bg-mint-soft disabled:cursor-not-allowed disabled:opacity-45"
            >
                <svg v-if="loading" class="spin-fast size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <span>{{ loading ? 'Перевіряємо…' : 'Застосувати' }}</span>
            </button>
        </form>

        <Transition name="pop">
            <p v-if="clientHint" id="promo-code-hint" aria-live="polite" class="mt-2.5 text-sm text-warn">
                {{ clientHint }}
            </p>
        </Transition>

        <Transition name="pop" mode="out-in">
            <p
                v-if="state === 'success'"
                key="success"
                role="status"
                class="mt-3 rounded-lg border border-mint-deep bg-mint-deep/40 px-3.5 py-2.5 text-sm text-mint"
            >
                Бонус <strong class="tabular-nums">+{{ money(lastBonus) }}</strong> нараховано.
                Новий баланс: <strong class="tabular-nums">{{ money(lastBalance) }}</strong>
            </p>

            <p
                v-else-if="state === 'error'"
                key="error"
                role="alert"
                class="mt-3 rounded-lg border border-danger-deep bg-danger-deep/40 px-3.5 py-2.5 text-sm text-danger"
            >
                {{ error }}
            </p>
        </Transition>
    </section>
</template>

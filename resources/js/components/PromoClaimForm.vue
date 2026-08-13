<script setup>
import { computed, onUnmounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import { money } from '../format';
import AppIcon from './AppIcon.vue';

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
    <section class="card-surface p-5 sm:p-6">
        <h2 class="text-base font-semibold tracking-tight">Застосувати промокод</h2>

        <form class="mt-4 flex flex-col gap-2.5 sm:flex-row" @submit.prevent="submit">
            <div class="relative flex-1">
                <input
                    id="promo-code"
                    v-model.trim="code"
                    type="text"
                    placeholder=" "
                    maxlength="12"
                    autocomplete="off"
                    spellcheck="false"
                    :disabled="loading"
                    :aria-invalid="clientHint ? 'true' : undefined"
                    :aria-describedby="clientHint ? 'promo-code-hint' : undefined"
                    class="float-input focus-ring font-mono tracking-[0.08em] uppercase"
                />
                <label class="float-label font-sans normal-case" for="promo-code">Промокод</label>
            </div>
            <button
                type="submit"
                :disabled="loading || !code"
                class="pressable focus-ring inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-ink px-7 text-sm font-semibold text-canvas transition-opacity duration-150 hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40 sm:min-h-14"
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
                class="mt-3 flex items-start gap-2.5 rounded-xl bg-card-2 px-3.5 py-2.5 text-sm text-pos"
            >
                <AppIcon name="check-circle" class="mt-0.5 size-4 shrink-0" />
                <span>
                    Бонус <strong class="tabular-nums">+{{ money(lastBonus) }}</strong> нараховано.
                    Новий баланс: <strong class="tabular-nums">{{ money(lastBalance) }}</strong>
                </span>
            </p>

            <p
                v-else-if="state === 'error'"
                key="error"
                role="alert"
                class="mt-3 flex items-start gap-2.5 rounded-xl bg-card-2 px-3.5 py-2.5 text-sm text-danger"
            >
                <AppIcon name="alert" class="mt-0.5 size-4 shrink-0" />
                <span>{{ error }}</span>
            </p>
        </Transition>
    </section>
</template>

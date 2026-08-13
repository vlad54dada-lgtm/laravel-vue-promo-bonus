<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import api, { errorMessage } from '../api';
import { money, dateTime } from '../format';

const emit = defineEmits(['balance-changed']);

const claims = ref([]);
const meta = ref(null);
const page = ref(1);
const statusFilter = ref('');
const loading = ref(false);
const error = ref(null);

// Тікет 2: скасування з підтвердженням
const revokeTarget = ref(null); // claim, що чекає підтвердження в модалці
const revokingId = ref(null);   // per-row loading: подвійний клік неможливий
const revokeError = ref(null);
const revokeSuccess = ref(null);
const modalEl = ref(null);

const FILTERS = [
    { value: '', label: 'Всі' },
    { value: 'applied', label: 'Застосовані' },
    { value: 'rejected', label: 'Відхилені' },
    { value: 'revoked', label: 'Скасовані' },
];

const STATUS_BADGES = {
    applied: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-red-50 text-red-700',
    revoked: 'bg-slate-100 text-slate-500',
};

const STATUS_LABELS = {
    applied: 'Застосовано',
    rejected: 'Відхилено',
    revoked: 'Скасовано',
};

const REASON_LABELS = {
    not_found: 'код не знайдено',
    expired: 'код прострочено',
    already_used: 'код уже використано',
};

// Лічильник запитів: відповідь, що прийшла після новішого запиту
// (швидке перемикання фільтрів/сторінок), ігнорується
let loadSeq = 0;

async function load() {
    const seq = ++loadSeq;
    loading.value = true;
    error.value = null;
    revokeError.value = null;
    revokeSuccess.value = null;
    try {
        const { data } = await api.get('/promo/history', {
            params: {
                page: page.value,
                ...(statusFilter.value ? { status: statusFilter.value } : {}),
            },
        });
        if (seq !== loadSeq) return;
        claims.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        if (seq !== loadSeq) return;
        error.value = errorMessage(e);
    } finally {
        if (seq === loadSeq) {
            loading.value = false;
        }
    }
}

watch(statusFilter, () => {
    if (page.value !== 1) {
        page.value = 1; // watch(page) сам викличе load() — без дубля запиту
    } else {
        load();
    }
});
watch(page, load);

onMounted(load);

defineExpose({ reload: load });

function askRevoke(claim) {
    revokeError.value = null;
    revokeSuccess.value = null;
    revokeTarget.value = claim;
}

// Фокус у модалку при відкритті (доступність: Esc теж працює лише з фокусом)
watch(revokeTarget, async (target) => {
    if (target) {
        await nextTick();
        modalEl.value?.focus();
    }
});

async function confirmRevoke() {
    const claim = revokeTarget.value;
    revokeTarget.value = null;
    revokingId.value = claim.id;

    let failure = null;
    let success = null;

    try {
        const { data } = await api.patch(`/promo/${claim.id}/revoke`);
        emit('balance-changed', data.balance_cents);
        success = `Бонус ${money(claim.amount_cents)} за кодом ${claim.code} скасовано, суму знято з балансу.`;
    } catch (e) {
        // Наприклад, уже скасовано в іншій вкладці — показуємо причину,
        // а баланс просимо перечитати з сервера (emit без аргументу)
        failure = errorMessage(e);
        emit('balance-changed');
    } finally {
        revokingId.value = null;
        await load(); // статус і список — з сервера, не з припущень клієнта
        revokeError.value = failure;
        revokeSuccess.value = success;
    }
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900">Історія промокодів</h2>

            <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                <button
                    v-for="filter in FILTERS"
                    :key="filter.value"
                    type="button"
                    class="rounded-md px-3 py-1 text-xs font-medium transition"
                    :class="statusFilter === filter.value
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-500 hover:text-slate-700'"
                    @click="statusFilter = filter.value"
                >
                    {{ filter.label }}
                </button>
            </div>
        </div>

        <p v-if="error" role="alert" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-if="revokeError" role="alert" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ revokeError }}</p>
        <p v-if="revokeSuccess" role="status" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ revokeSuccess }}</p>

        <p v-if="!error && !loading && claims.length === 0" class="mt-6 text-center text-sm text-slate-400">
            Поки що порожньо — застосуйте свій перший промокод.
        </p>

        <ul v-if="claims.length > 0" class="mt-4 divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
            <li
                v-for="claim in claims"
                :key="claim.id"
                class="flex flex-wrap items-center justify-between gap-3 py-3"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-semibold text-slate-900">{{ claim.code }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="STATUS_BADGES[claim.status]"
                        >
                            {{ STATUS_LABELS[claim.status] }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ dateTime(claim.created_at) }}
                        <template v-if="claim.reject_reason"> · {{ REASON_LABELS[claim.reject_reason] }}</template>
                        <template v-if="claim.revoked_at"> · скасовано {{ dateTime(claim.revoked_at) }}</template>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="text-sm font-semibold tabular-nums"
                        :class="claim.status === 'applied' ? 'text-emerald-600' : 'text-slate-400'"
                    >
                        <template v-if="claim.amount_cents !== null">
                            {{ claim.status === 'revoked' ? '−' : '+' }}{{ money(claim.amount_cents) }}
                        </template>
                        <template v-else>—</template>
                    </span>

                    <button
                        v-if="claim.status === 'applied'"
                        type="button"
                        :disabled="revokingId === claim.id"
                        class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="askRevoke(claim)"
                    >
                        {{ revokingId === claim.id ? 'Скасовуємо…' : 'Скасувати' }}
                    </button>
                </div>
            </li>
        </ul>

        <div
            v-if="meta && meta.total > meta.per_page"
            class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm"
        >
            <button
                type="button"
                :disabled="page <= 1 || loading"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                @click="page--"
            >
                ← Назад
            </button>
            <span class="text-slate-400">Сторінка {{ meta.current_page }} з {{ meta.last_page }}</span>
            <button
                type="button"
                :disabled="page >= meta.last_page || loading"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                @click="page++"
            >
                Далі →
            </button>
        </div>

        <!-- Підтвердження скасування (Тікет 2) -->
        <div
            v-if="revokeTarget"
            ref="modalEl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="revoke-modal-title"
            tabindex="-1"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 focus:outline-none"
            @click.self="revokeTarget = null"
            @keydown.esc="revokeTarget = null"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
                <h3 id="revoke-modal-title" class="text-base font-semibold text-slate-900">Скасувати нарахування?</h3>
                <p class="mt-2 text-sm text-slate-600">
                    Бонус <strong>+{{ money(revokeTarget.amount_cents) }}</strong> за кодом
                    <span class="font-mono font-semibold">{{ revokeTarget.code }}</span>
                    буде скасовано, а сума — знята з балансу.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        @click="revokeTarget = null"
                    >
                        Залишити
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700"
                        @click="confirmRevoke"
                    >
                        Так, скасувати
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

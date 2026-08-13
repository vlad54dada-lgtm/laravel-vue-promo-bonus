<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import api, { errorMessage } from '../api';
import { money, dateTime } from '../format';
import AppIcon from './AppIcon.vue';

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

let successTimer = null;

const FILTERS = [
    { value: '', label: 'Всі' },
    { value: 'applied', label: 'Застосовані' },
    { value: 'rejected', label: 'Відхилені' },
    { value: 'revoked', label: 'Скасовані' },
];

const STATUS_ICONS = {
    applied: 'check',
    rejected: 'x',
    revoked: 'undo',
};

const STATUS_ICON_WRAP = {
    applied: 'bg-mint-deep/60 text-mint',
    rejected: 'bg-danger-deep/60 text-danger',
    revoked: 'bg-card-2 text-ink-faint',
};

const STATUS_BADGES = {
    applied: 'bg-mint-deep/60 text-mint',
    rejected: 'bg-danger-deep/60 text-danger',
    revoked: 'bg-card-2 text-ink-faint',
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

/**
 * SWR-кеш сторінок історії: перемикання фільтрів/сторінок показує
 * закешовані дані миттєво і тихо ревалідовує у фоні. Після першого
 * завантаження решта фільтрів префетчиться — таби відчуваються інстант.
 */
const cache = new Map(); // `${status}|${page}` -> { data, meta }
let prefetched = false;
let loadSeq = 0;

const cacheKey = () => `${statusFilter.value}|${page.value}`;

function historyParams(status, pageNumber) {
    return { page: pageNumber, ...(status ? { status } : {}) };
}

async function load() {
    const seq = ++loadSeq;
    const key = cacheKey();
    const cached = cache.get(key);

    error.value = null;
    revokeError.value = null;
    revokeSuccess.value = null;

    if (cached) {
        // Миттєвий показ; свіжа версія доїде тихо
        claims.value = cached.data;
        meta.value = cached.meta;
    } else {
        claims.value = [];
        loading.value = true;
    }

    try {
        const { data } = await api.get('/promo/history', {
            params: historyParams(statusFilter.value, page.value),
        });
        cache.set(key, { data: data.data, meta: data.meta });
        if (seq !== loadSeq) return;
        claims.value = data.data;
        meta.value = data.meta;
        prefetchFilters();
    } catch (e) {
        if (seq !== loadSeq) return;
        if (!cached) {
            error.value = errorMessage(e);
        }
    } finally {
        if (seq === loadSeq) {
            loading.value = false;
        }
    }
}

function prefetchFilters() {
    if (prefetched) return;
    prefetched = true;
    for (const filter of FILTERS) {
        const key = `${filter.value}|1`;
        if (cache.has(key)) continue;
        api.get('/promo/history', { params: historyParams(filter.value, 1) })
            .then(({ data }) => cache.set(key, { data: data.data, meta: data.meta }))
            .catch(() => {}); // префетч — best effort
    }
}

/** Дані змінилися (claim/revoke) — кеш недійсний. */
function reload() {
    cache.clear();
    prefetched = false;
    return load();
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

defineExpose({ reload });

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
        await reload(); // статус і список — з сервера, не з припущень клієнта
        revokeError.value = failure;
        revokeSuccess.value = success;
        if (success) {
            clearTimeout(successTimer);
            successTimer = setTimeout(() => {
                revokeSuccess.value = null;
            }, 5000);
        }
    }
}
</script>

<template>
    <section class="card-surface p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold tracking-tight">Історія промокодів</h2>

            <div class="flex gap-0.5 rounded-lg border border-line-soft bg-card-2/70 p-1" role="tablist" aria-label="Фільтр за статусом">
                <button
                    v-for="filter in FILTERS"
                    :key="filter.value"
                    type="button"
                    role="tab"
                    :aria-selected="statusFilter === filter.value"
                    class="focus-ring min-h-9 rounded-md px-3 text-xs font-medium transition-colors duration-150"
                    :class="statusFilter === filter.value
                        ? 'bg-canvas text-ink'
                        : 'text-ink-faint hover:text-ink-soft'"
                    @click="statusFilter = filter.value"
                >
                    {{ filter.label }}
                </button>
            </div>
        </div>

        <Transition name="pop">
            <p v-if="error" role="alert" class="mt-4 flex items-start gap-2.5 rounded-lg border border-danger-deep bg-danger-deep/40 px-3.5 py-2.5 text-sm text-danger">
                <AppIcon name="alert" class="mt-0.5 size-4 shrink-0" />
                <span>{{ error }}</span>
            </p>
        </Transition>
        <Transition name="pop">
            <p v-if="revokeError" role="alert" class="mt-4 flex items-start gap-2.5 rounded-lg border border-danger-deep bg-danger-deep/40 px-3.5 py-2.5 text-sm text-danger">
                <AppIcon name="alert" class="mt-0.5 size-4 shrink-0" />
                <span>{{ revokeError }}</span>
            </p>
        </Transition>
        <Transition name="pop">
            <p v-if="revokeSuccess" role="status" class="mt-4 flex items-start gap-2.5 rounded-lg border border-mint-deep bg-mint-deep/40 px-3.5 py-2.5 text-sm text-mint">
                <AppIcon name="check-circle" class="mt-0.5 size-4 shrink-0" />
                <span>{{ revokeSuccess }}</span>
            </p>
        </Transition>

        <!-- Скелет: тільки коли для цього фільтра ще немає даних -->
        <div v-if="loading && claims.length === 0" class="mt-4 space-y-3" aria-hidden="true">
            <div v-for="i in 3" :key="i" class="flex animate-pulse items-center gap-3 py-2">
                <div class="size-9 rounded-full bg-card-2" />
                <div class="flex-1 space-y-2">
                    <div class="h-4 w-36 rounded bg-card-2" />
                    <div class="h-3 w-24 rounded bg-card-2" />
                </div>
                <div class="h-4 w-16 rounded bg-card-2" />
            </div>
        </div>

        <div
            v-else-if="!error && !loading && claims.length === 0"
            class="mt-2 py-8 text-center"
        >
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-card-2 text-ink-faint">
                <AppIcon name="ticket" class="size-5" />
            </span>
            <p class="mt-3 text-sm text-ink-faint">
                {{ statusFilter
                    ? 'За цим фільтром поки що нічого немає.'
                    : 'Введіть промокод у формі вище — нарахування і всі спроби з’являться тут.' }}
            </p>
        </div>

        <ul v-if="claims.length > 0" class="mt-3 divide-y divide-line-soft">
            <li
                v-for="claim in claims"
                :key="claim.id"
                class="flex flex-wrap items-center gap-3 py-3"
            >
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-full"
                    :class="STATUS_ICON_WRAP[claim.status]"
                    aria-hidden="true"
                >
                    <AppIcon :name="STATUS_ICONS[claim.status]" class="size-4" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-semibold tracking-wide">{{ claim.code }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="STATUS_BADGES[claim.status]"
                        >
                            {{ STATUS_LABELS[claim.status] }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-ink-faint">
                        {{ dateTime(claim.created_at) }}
                        <template v-if="claim.reject_reason"> · {{ REASON_LABELS[claim.reject_reason] }}</template>
                        <template v-if="claim.revoked_at"> · скасовано {{ dateTime(claim.revoked_at) }}</template>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="text-sm font-semibold tabular-nums"
                        :class="{
                            'text-mint': claim.status === 'applied',
                            'text-ink-faint': claim.status !== 'applied',
                        }"
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
                        class="pressable focus-ring min-h-10 rounded-lg border border-danger-deep px-3.5 text-xs font-medium text-danger transition-colors duration-150 hover:bg-danger-deep/40 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="askRevoke(claim)"
                    >
                        {{ revokingId === claim.id ? 'Скасовуємо…' : 'Скасувати' }}
                    </button>
                </div>
            </li>
        </ul>

        <div
            v-if="meta && meta.total > meta.per_page"
            class="mt-4 flex items-center justify-between border-t border-line-soft pt-4 text-sm"
        >
            <button
                type="button"
                :disabled="page <= 1 || loading"
                class="pressable focus-ring inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-line px-3.5 text-ink-soft transition-colors duration-150 hover:bg-card-2 hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
                @click="page--"
            >
                <AppIcon name="chevron-left" class="size-4" />
                Назад
            </button>
            <span class="text-ink-faint tabular-nums">Сторінка {{ meta.current_page }} з {{ meta.last_page }}</span>
            <button
                type="button"
                :disabled="page >= meta.last_page || loading"
                class="pressable focus-ring inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-line px-3.5 text-ink-soft transition-colors duration-150 hover:bg-card-2 hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
                @click="page++"
            >
                Далі
                <AppIcon name="chevron-right" class="size-4" />
            </button>
        </div>

        <!-- Підтвердження скасування (Тікет 2) -->
        <Teleport to="body">
            <Transition name="modal">
                <div
                    v-if="revokeTarget"
                    ref="modalEl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="revoke-modal-title"
                    tabindex="-1"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px] focus:outline-none"
                    @click.self="revokeTarget = null"
                    @keydown.esc="revokeTarget = null"
                >
                    <div class="modal-panel w-full max-w-sm rounded-2xl bg-card-2 p-6 shadow-[inset_0_1px_0_rgba(255,255,255,0.05),0_24px_48px_rgba(0,0,0,0.55)]">
                        <span class="grid size-10 place-items-center rounded-full bg-danger-deep/60 text-danger">
                            <AppIcon name="undo" class="size-5" />
                        </span>
                        <h3 id="revoke-modal-title" class="mt-4 text-base font-semibold tracking-tight">Скасувати нарахування?</h3>
                        <p class="mt-2 text-sm leading-relaxed text-ink-soft">
                            Бонус <strong class="text-ink tabular-nums">+{{ money(revokeTarget.amount_cents) }}</strong> за кодом
                            <span class="font-mono font-semibold text-ink">{{ revokeTarget.code }}</span>
                            буде скасовано, а сума — знята з балансу.
                        </p>
                        <div class="mt-6 flex justify-end gap-2">
                            <button
                                type="button"
                                class="pressable focus-ring min-h-10 rounded-lg border border-line px-4 text-sm font-medium text-ink-soft transition-colors duration-150 hover:bg-card hover:text-ink"
                                @click="revokeTarget = null"
                            >
                                Залишити
                            </button>
                            <button
                                type="button"
                                class="pressable focus-ring min-h-10 rounded-lg bg-danger-strong px-4 text-sm font-semibold text-white transition-[filter] duration-150 hover:brightness-110"
                                @click="confirmRevoke"
                            >
                                Так, скасувати
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </section>
</template>

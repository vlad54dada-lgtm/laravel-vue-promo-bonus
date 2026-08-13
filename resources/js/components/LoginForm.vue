<script setup>
import { ref } from 'vue';
import api, { setToken, errorMessage } from '../api';
import AppIcon from './AppIcon.vue';

const emit = defineEmits(['logged-in']);

const email = ref('player@demo.test');
const password = ref('password');
const loading = ref(false);
const error = ref(null);

async function submit() {
    if (loading.value) {
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/auth/login', {
            email: email.value,
            password: password.value,
        });
        setToken(data.token);
        emit('logged-in', data.user);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-dvh items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <p class="mb-6 flex items-center justify-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-xl bg-mint-deep/70 text-mint">
                    <AppIcon name="ticket" class="size-4.5" />
                </span>
                <span class="text-lg font-semibold tracking-tight text-ink">Promo Bonus</span>
            </p>

            <div class="card-surface p-7">
                <h1 class="text-xl font-semibold tracking-tight">Вхід гравця</h1>
                <p class="mt-1.5 text-sm text-ink-soft">
                    Демо-акаунт:
                    <code class="rounded bg-card-2 px-1.5 py-0.5 font-mono text-xs text-ink">player@demo.test</code>
                    /
                    <code class="rounded bg-card-2 px-1.5 py-0.5 font-mono text-xs text-ink">password</code>
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="email">Email</label>
                        <input
                            id="email"
                            v-model="email"
                            type="email"
                            required
                            autocomplete="username"
                            class="focus-ring min-h-11 w-full rounded-lg border border-line bg-canvas px-3.5 text-sm text-ink transition-colors duration-150 placeholder:text-ink-faint focus:border-mint"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="password">Пароль</label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            class="focus-ring min-h-11 w-full rounded-lg border border-line bg-canvas px-3.5 text-sm text-ink transition-colors duration-150 focus:border-mint"
                        />
                    </div>

                    <Transition name="pop">
                        <p
                            v-if="error"
                            role="alert"
                            class="rounded-lg border border-danger-deep bg-danger-deep/40 px-3.5 py-2.5 text-sm text-danger"
                        >
                            {{ error }}
                        </p>
                    </Transition>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="pressable focus-ring btn-glow min-h-11 w-full rounded-lg bg-mint text-sm font-semibold text-mint-ink transition-[background-color,opacity] duration-150 hover:bg-mint-soft disabled:cursor-not-allowed disabled:opacity-45 disabled:shadow-none"
                    >
                        {{ loading ? 'Входимо…' : 'Увійти' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

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
                <AppIcon name="ticket" class="size-5 text-ink" />
                <span class="text-lg font-bold tracking-tight text-ink">Promo Bonus</span>
            </p>

            <div class="card-surface p-7">
                <h1 class="text-xl font-semibold tracking-tight">Вхід гравця</h1>
                <p class="mt-1.5 text-sm text-ink-soft">
                    Демо-акаунт:
                    <code class="rounded bg-card-2 px-1.5 py-0.5 font-mono text-xs text-ink">player@demo.test</code>
                    /
                    <code class="rounded bg-card-2 px-1.5 py-0.5 font-mono text-xs text-ink">password</code>
                </p>

                <form class="mt-6 space-y-3.5" @submit.prevent="submit">
                    <div class="relative">
                        <input
                            id="email"
                            v-model="email"
                            type="email"
                            required
                            autocomplete="username"
                            placeholder=" "
                            class="float-input focus-ring"
                        />
                        <label class="float-label" for="email">Email</label>
                    </div>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder=" "
                            class="float-input focus-ring"
                        />
                        <label class="float-label" for="password">Пароль</label>
                    </div>

                    <Transition name="pop">
                        <p
                            v-if="error"
                            role="alert"
                            class="flex items-start gap-2.5 rounded-xl bg-card-2 px-3.5 py-2.5 text-sm text-danger"
                        >
                            <AppIcon name="alert" class="mt-0.5 size-4 shrink-0" />
                            <span>{{ error }}</span>
                        </p>
                    </Transition>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="pressable focus-ring min-h-12 w-full rounded-full bg-ink text-sm font-semibold text-canvas transition-opacity duration-150 hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {{ loading ? 'Входимо…' : 'Увійти' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import api, { setToken, errorMessage } from '../api';

const emit = defineEmits(['logged-in']);

const email = ref('player@demo.test');
const password = ref('password');
const loading = ref(false);
const error = ref(null);

async function submit() {
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
    <div class="mx-auto mt-16 w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Вхід гравця</h1>
        <p class="mt-1 text-sm text-slate-500">
            Демо-акаунт: <span class="font-mono">player@demo.test</span> / <span class="font-mono">password</span>
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="email">Email</label>
                <input
                    id="email"
                    v-model="email"
                    type="email"
                    required
                    autocomplete="username"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="password">Пароль</label>
                <input
                    id="password"
                    v-model="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
            </div>

            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <button
                type="submit"
                :disabled="loading"
                class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ loading ? 'Входимо…' : 'Увійти' }}
            </button>
        </form>
    </div>
</template>

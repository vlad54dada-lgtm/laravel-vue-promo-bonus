import axios from 'axios';

/**
 * Єдиний axios-інстанс застосунку: Bearer-токен з localStorage,
 * автоматичний розлогін на 401.
 */
const api = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
});

const TOKEN_KEY = 'auth_token';

export function setToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
}

export function hasToken() {
    return localStorage.getItem(TOKEN_KEY) !== null;
}

api.interceptors.request.use((config) => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            clearToken();
            window.dispatchEvent(new CustomEvent('auth:logout'));
        }
        return Promise.reject(error);
    },
);

/**
 * Людиночитане повідомлення з відповіді API:
 * 422 → перша помилка валідації, доменні помилки → message,
 * мережеві збої → загальний текст.
 */
export function errorMessage(error) {
    const data = error.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat()[0];
    }
    if (data?.message) {
        return data.message;
    }
    return 'Щось пішло не так. Спробуйте ще раз.';
}

export default api;

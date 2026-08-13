<script setup>
/**
 * Мінімальний набір інлайн-іконок (Lucide-геометрія, stroke 2, round).
 * Одна сім'я, один stroke — жодних emoji в ролі іконок.
 */
defineProps({
    name: { type: String, required: true },
});

const ICONS = {
    ticket: [
        'M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z',
        'M13 5v2', 'M13 11v2', 'M13 17v2',
    ],
    check: ['M20 6 9 17l-5-5'],
    x: ['M18 6 6 18', 'M6 6l12 12'],
    undo: ['M3 7v6h6', 'M21 17a9 9 0 0 0-15-6.7L3 13'],
    'chevron-left': ['m15 18-6-6 6-6'],
    'chevron-right': ['m9 18 6-6-6-6'],
    'log-out': ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9'],
    'check-circle': ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z', 'm9 12 2 2 4-4'],
    alert: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z', 'M12 8v4', 'M12 16h.01'],
};
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path v-for="(d, i) in ICONS[name]" :key="i" :d="d" />
    </svg>
</template>

/**
 * Гроші зберігаються і передаються в мінорних одиницях (центах).
 * Форматування для показу — тільки тут.
 */
export function money(cents) {
    if (cents === null || cents === undefined) {
        return '—';
    }
    return (cents / 100).toLocaleString('uk-UA', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function dateTime(iso) {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('uk-UA', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

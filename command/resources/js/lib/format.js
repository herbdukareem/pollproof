const n = new Intl.NumberFormat('en-NG');

export const num = (v) => (v == null ? '–' : n.format(v));
export const pct = (v, digits = 0) => (v == null ? '–' : `${(v * 100).toFixed(digits)}%`);
export const signed = (v) => (v > 0 ? `+${n.format(v)}` : n.format(v));

export function time(v) {
    if (!v) return '–';
    return new Date(v).toLocaleTimeString('en-NG', { hour: 'numeric', minute: '2-digit' });
}

export function dateTime(v) {
    if (!v) return '–';
    return new Date(v).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
}

export const titleCase = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Local datetime value for <input type="datetime-local"> from a Date. */
export function localInputValue(d = new Date()) {
    const pad = (x) => String(x).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

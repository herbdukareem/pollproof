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

/** The time if it was today, otherwise the date and time: "8:05 am" or "6 Feb, 8:05 am". */
export function timeOrDate(v, now = new Date()) {
    if (!v) return '–';
    return new Date(v).toDateString() === now.toDateString() ? time(v) : dateTime(v);
}

/**
 * How long ago, for queues: "just now", "12 min", "1 h 05 min", "3 d".
 * A time ahead of this device's clock (a wrong clock, or demo data dated election day) shows the date and time instead of a false "0 min".
 */
export function ago(v, now = Date.now()) {
    if (!v) return '–';
    const mins = Math.floor((now - new Date(v)) / 60000);
    if (mins < -1) return dateTime(v);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min`;
    if (mins < 1440) return `${Math.floor(mins / 60)} h ${String(mins % 60).padStart(2, '0')} min`;
    return `${Math.floor(mins / 1440)} d`;
}

/** Minutes since a time, or null when it is in the future. */
export const minutesSince = (v, now = Date.now()) => {
    const m = Math.floor((now - new Date(v)) / 60000);
    return m < -1 ? null : Math.max(0, m);
};

export const titleCase = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Local datetime value for <input type="datetime-local"> from a Date. */
export function localInputValue(d = new Date()) {
    const pad = (x) => String(x).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

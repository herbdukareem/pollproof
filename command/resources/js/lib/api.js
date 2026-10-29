import { track } from './progress';

// Thin fetch wrapper for the PollProof API. The browser signs in with a session cookie
// (Sanctum stateful), so every call sends the XSRF token Laravel set in a cookie.

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || `Request failed (${status})`);
        this.status = status;
        this.body = body;
        this.errors = body?.errors || {};
    }

    firstError() {
        const first = Object.values(this.errors)[0];
        return Array.isArray(first) ? first[0] : this.message;
    }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

export async function ensureCsrfCookie() {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
}

export function request(method, url, body = undefined) {
    return track(send(method, url, body), method !== 'GET');
}

async function send(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;

    let payload;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });
    const isJson = (res.headers.get('content-type') || '').includes('application/json');
    const data = isJson ? await res.json() : null;

    if (res.status === 401 && !url.startsWith('/session')) onUnauthorized();
    if (!res.ok) throw new ApiError(res.status, data);
    return data;
}

const v1 = (path) => `/api/v1/${path.replace(/^\//, '')}`;

export const api = {
    get: (path, params) => {
        const qs = params ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)) : '';
        return request('GET', v1(path) + qs);
    },
    post: (path, body) => request('POST', v1(path), body),
    patch: (path, body) => request('PATCH', v1(path), body),
    delete: (path, body) => request('DELETE', v1(path), body),
    url: v1,
};

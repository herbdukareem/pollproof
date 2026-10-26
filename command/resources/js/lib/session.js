import { reactive } from 'vue';
import { api, ensureCsrfCookie, request } from './api';

export const session = reactive({
    user: null,
    loaded: false,
});

export async function loadSession() {
    try {
        session.user = await api.get('me');
    } catch {
        session.user = null;
    } finally {
        session.loaded = true;
    }
    return session.user;
}

export async function signIn(email, password) {
    await ensureCsrfCookie();
    await request('POST', '/session', { email, password });
    const user = await loadSession();
    if (!user) {
        // Signed in, but the API did not accept the session cookie: the host is missing from SANCTUM_STATEFUL_DOMAINS.
        throw Object.assign(new Error('Signed in, but this address is not allowed to use the API. Check SANCTUM_STATEFUL_DOMAINS.'), { firstError() { return this.message; } });
    }
    return user;
}

export async function signOut() {
    try {
        await request('DELETE', '/session');
    } finally {
        session.user = null;
    }
}

export function hasRole(...roles) {
    return !!session.user && roles.includes(session.user.role);
}

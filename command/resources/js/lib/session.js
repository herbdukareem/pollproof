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

/** Returns the user, or { twoFactor: true } when a code from the authenticator app is needed next. */
export async function signIn(email, password) {
    await ensureCsrfCookie();
    const res = await request('POST', '/session', { email, password });
    if (res?.two_factor) return { twoFactor: true };
    return finishSignIn();
}

export async function signInWithCode(code) {
    await request('POST', '/session/two-factor', { code });
    return finishSignIn();
}

async function finishSignIn() {
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

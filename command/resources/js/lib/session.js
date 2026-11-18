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

const failure = (message) => Object.assign(new Error(message), { firstError() { return this.message; } });

async function finishSignIn() {
    try {
        session.user = await api.get('me');
        return session.user;
    } catch (e) {
        session.user = null;
        // Only a 401 means the API ignored the session cookie (this address isn't one of the app's own).
        if (e.status === 401) throw failure('Signed in, but this address is not allowed to use the API. Check SANCTUM_STATEFUL_DOMAINS.');
        // Anything else is the server failing to load the account, most often an update whose
        // database changes haven't been applied yet.
        throw failure(`Signed in, but the server couldn't load your account (error ${e.status ?? 'network'}). If the site was just updated, run php artisan migrate --force on the server; otherwise see storage/logs.`);
    } finally {
        session.loaded = true;
    }
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

<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PinLogo from '../components/PinLogo.vue';
import { signIn } from '../lib/session';
import { homeFor } from '../lib/nav';

const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        const user = await signIn(email.value, password.value);
        const next = route.query.next;
        router.replace(typeof next === 'string' && next.startsWith('/') ? next : homeFor(user?.role));
    } catch (e) {
        error.value = e.status === 429 ? 'Too many attempts. Wait a minute and try again.' : (e.firstError?.() ?? 'Sign-in failed.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-navy lg:flex-row">
        <section class="relative flex flex-col justify-between overflow-hidden px-8 py-10 text-white lg:w-[46%] lg:px-14 lg:py-14">
            <div class="flex items-center gap-3">
                <PinLogo :size="40" />
                <span class="text-[28px] tracking-tight"><b class="font-extrabold">Poll</b><span class="font-medium">Proof</span></span>
            </div>
            <div class="mt-12 max-w-md lg:mt-0">
                <h1 class="text-4xl leading-tight font-extrabold tracking-tight lg:text-5xl">Protect every vote you win.</h1>
                <p class="mt-4 text-[17px] leading-relaxed text-[#c9d1de]">The command room for your governorship campaign, from polling unit to declaration.</p>
            </div>
            <p class="mt-10 text-sm text-[#8d98ab] lg:mt-0">by Ashlab Technologies</p>
            <svg class="pointer-events-none absolute -right-24 -bottom-24 h-96 w-96 opacity-[0.06]" viewBox="0 0 64 64" aria-hidden="true">
                <path d="M32 4C19.3 4 9 14.1 9 26.6C9 42 32 60 32 60C32 60 55 42 55 26.6C55 14.1 44.7 4 32 4Z" fill="#fff" />
            </svg>
        </section>
        <section class="flex flex-1 items-center justify-center rounded-t-3xl bg-mist px-6 py-12 lg:rounded-t-none lg:rounded-l-3xl">
            <form class="card w-full max-w-sm p-7" @submit.prevent="submit">
                <h2 class="text-2xl font-extrabold tracking-tight">Sign in</h2>
                <p class="mt-1 mb-6 text-sm text-ink-2">Command-room staff only. Agents use the mobile app.</p>
                <label class="label" for="email">Email</label>
                <input id="email" v-model="email" class="field mb-4" type="email" autocomplete="username" required />
                <label class="label" for="password">Password</label>
                <input id="password" v-model="password" class="field" type="password" autocomplete="current-password" required />
                <p v-if="error" role="alert" class="mt-4 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                <button class="btn-primary mt-6 h-12 w-full text-base" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
            </form>
        </section>
    </div>
</template>

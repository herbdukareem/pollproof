<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PinLogo from '../components/PinLogo.vue';
import { signIn, signInWithCode } from '../lib/session';
import { homeFor } from '../lib/nav';

const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);
const step = ref('password');
const code = ref('');
const recovery = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        const user = step.value === 'password' ? await signIn(email.value, password.value) : await signInWithCode(code.value);
        if (user?.twoFactor) {
            step.value = 'code';
            return;
        }
        const next = route.query.next;
        router.replace(typeof next === 'string' && next.startsWith('/') ? next : homeFor(user?.role));
    } catch (e) {
        error.value = e.status === 429 ? 'Too many attempts. Wait a minute and try again.' : (e.firstError?.() ?? 'Sign-in failed.');
        if (step.value === 'code' && /Sign in again/.test(error.value)) {
            step.value = 'password';
            code.value = '';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-navy lg:flex-row">
        <section class="flex flex-col justify-between px-6 pt-8 pb-10 text-white sm:px-10 lg:w-[46%] lg:px-14 lg:py-14">
            <div class="flex items-center gap-3">
                <PinLogo :size="36" />
                <span class="text-[25px] tracking-[-0.02em]"><b class="font-[800]">Poll</b><span class="font-[460]">Proof</span></span>
            </div>
            <div class="mt-10 max-w-md lg:mt-0">
                <h1 class="text-[34px] leading-[1.1] font-[720] tracking-[-0.02em] lg:text-[46px]">Protect every vote you win.</h1>
                <p class="mt-4 text-[16px] leading-relaxed text-[#c9d1de]">The command room for your governorship campaign, from the polling unit to the declaration.</p>
                <figure class="mt-10 hidden sm:block" aria-hidden="true">
                    <div class="relative flex gap-[3px]">
                        <i v-for="n in 25" :key="n" class="h-9 flex-1 rounded-[2px]" :class="n <= 19 ? 'bg-[#3a4d72]' : 'bg-orange'"></i>
                        <span class="absolute -top-2 -bottom-2 w-0.5 bg-white" :style="{ left: 'calc(68% - 1px)' }"></span>
                    </div>
                    <figcaption class="mt-3 text-[13.5px] leading-snug text-[#9eabc2]">A governor needs a quarter of the vote in two-thirds of the LGAs. PollProof keeps that count, unit by unit, from your agents' signed results.</figcaption>
                </figure>
            </div>
            <p class="mt-10 hidden text-[13px] text-[#8d98ab] lg:mt-0 lg:block">By Ashlab Technologies</p>
        </section>
        <section class="flex flex-1 items-center justify-center bg-mist px-4 py-10 sm:px-6 sm:py-12">
            <form class="card w-full max-w-sm p-6 sm:p-7" @submit.prevent="submit">
                <template v-if="step === 'password'">
                    <h2 class="text-[24px] leading-tight font-[720] tracking-[-0.01em]">Sign in</h2>
                    <p class="mt-1 mb-6 text-sm text-ink-2">For command-room staff. Agents use the mobile app.</p>
                    <label class="label" for="email">Email</label>
                    <input id="email" v-model="email" class="field mb-4" type="email" autocomplete="username" required />
                    <label class="label" for="password">Password</label>
                    <input id="password" v-model="password" class="field" type="password" autocomplete="current-password" required />
                </template>
                <template v-else>
                    <h2 class="text-[24px] leading-tight font-[720] tracking-[-0.01em]">Two-step sign-in</h2>
                    <p class="mt-1 mb-6 text-sm text-ink-2">{{ recovery ? 'Type one of the recovery codes you saved. Each works once.' : 'Open your authenticator app and type the 6-digit code for PollProof.' }}</p>
                    <label class="label" for="code">{{ recovery ? 'Recovery code' : 'Code' }}</label>
                    <input
                        id="code"
                        v-model="code"
                        class="field h-12 text-center font-mono text-[20px] tracking-[0.3em]"
                        :inputmode="recovery ? 'text' : 'numeric'"
                        :autocomplete="recovery ? 'off' : 'one-time-code'"
                        :maxlength="recovery ? 11 : 6"
                        :placeholder="recovery ? 'xxxxx-xxxxx' : '000000'"
                        autofocus
                        required
                    />
                    <button type="button" class="mt-3 text-sm font-[600] text-navy underline-offset-2 hover:underline" @click="recovery = !recovery; code = ''">
                        {{ recovery ? 'Use the app code instead' : "Can't use your phone? Use a recovery code" }}
                    </button>
                </template>
                <p v-if="error" role="alert" class="mt-4 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                <button class="btn-primary mt-6 h-11 w-full text-[15px]" :disabled="busy">{{ busy ? 'Checking…' : step === 'password' ? 'Sign in' : 'Continue' }}</button>
                <button v-if="step === 'code'" type="button" class="mt-3 w-full text-sm text-ink-2 hover:text-ink" @click="step = 'password'; code = ''; error = ''">Back</button>
            </form>
        </section>
    </div>
</template>

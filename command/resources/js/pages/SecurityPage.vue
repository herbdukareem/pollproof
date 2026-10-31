<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import QRCode from 'qrcode';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { loadSession, session } from '../lib/session';
import { homeFor } from '../lib/nav';

const router = useRouter();
const status = useResource(() => api.get('me/two-factor'));
const s = computed(() => status.data.value);
const forced = computed(() => session.user?.two_factor_required);
const step = ref('idle'); // idle → password → scan → codes
const purpose = ref('enable');
const password = ref('');
const code = ref('');
const setup = ref(null);
const qr = ref('');
const codes = ref([]);
const error = ref('');
const busy = ref(false);

function begin(p) {
    purpose.value = p;
    step.value = 'password';
    password.value = '';
    error.value = '';
}

async function withPassword() {
    busy.value = true;
    error.value = '';
    try {
        if (purpose.value === 'enable') {
            setup.value = await api.post('me/two-factor', { password: password.value });
            qr.value = await QRCode.toDataURL(setup.value.uri, { margin: 1, width: 220, color: { dark: '#13213c' } });
            step.value = 'scan';
        } else if (purpose.value === 'codes') {
            codes.value = (await api.post('me/two-factor/recovery-codes', { password: password.value })).recovery_codes;
            step.value = 'codes';
        } else {
            await api.delete('me/two-factor', { password: password.value });
            step.value = 'idle';
            await status.load();
        }
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    busy.value = true;
    error.value = '';
    try {
        codes.value = (await api.post('me/two-factor/confirm', { code: code.value })).recovery_codes;
        step.value = 'codes';
        await Promise.all([status.load(), loadSession()]);
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

const wasForced = !!session.user?.two_factor_required;
function finish() {
    step.value = 'idle';
    codes.value = [];
    if (wasForced) router.replace(homeFor(session.user.role));
}

function download() {
    const url = URL.createObjectURL(new Blob([`PollProof recovery codes for ${session.user?.name}\nEach code works once.\n\n${codes.value.join('\n')}\n`], { type: 'text/plain' }));
    Object.assign(document.createElement('a'), { href: url, download: 'pollproof-recovery-codes.txt' }).click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <PageHeader :back="forced ? '' : '/'" title="Account security" :subtitle="forced ? 'Your campaign requires two-step sign-in. Set it up to continue.' : 'Your password and two-step sign-in.'" />
    <LoadState :loading="status.loading.value && !s" :error="status.error.value" :empty="!s">
        <div class="grid max-w-4xl items-start gap-5 lg:grid-cols-[1fr_300px]">
            <section class="card p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="card-title ">Two-step sign-in</h2>
                    <Badge :tone="s.enabled ? 'good' : 'warn'">{{ s.enabled ? 'On' : 'Off' }}</Badge>
                </div>
                <p class="mt-1 text-sm text-ink-2">After your password, you type a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy). Someone with your password alone can't get in.</p>

                <template v-if="step === 'idle'">
                    <div v-if="s.enabled" class="mt-4 flex flex-wrap gap-3">
                        <span class="self-center text-sm text-ink-2">{{ s.recovery_codes_left }} recovery codes left</span>
                        <button class="btn-outline" @click="begin('codes')">New recovery codes</button>
                        <button v-if="!s.required" class="btn-outline" @click="begin('disable')">Turn off</button>
                    </div>
                    <button v-else class="btn-primary mt-4" @click="begin('enable')">Set up two-step sign-in</button>
                </template>

                <form v-if="step === 'password'" class="mt-4 flex max-w-sm flex-col gap-3" @submit.prevent="withPassword">
                    <label class="label !mb-0" for="pw">Confirm your password</label>
                    <input id="pw" v-model="password" type="password" autocomplete="current-password" class="field" required autofocus />
                    <div class="flex gap-2">
                        <button class="btn-navy" :disabled="busy">{{ purpose === 'disable' ? 'Turn off' : 'Continue' }}</button>
                        <button type="button" class="btn-outline" @click="step = 'idle'">Cancel</button>
                    </div>
                </form>

                <div v-if="step === 'scan'" class="mt-5 grid gap-5 sm:grid-cols-[220px_1fr]">
                    <img :src="qr" alt="QR code to add PollProof to your authenticator app" class="h-[220px] w-[220px] rounded-xl border border-line" />
                    <form class="flex flex-col gap-3" @submit.prevent="confirm">
                        <ol class="list-decimal pl-5 text-sm text-ink-2">
                            <li>In your authenticator app, add an account and scan this code.</li>
                            <li>Type the 6-digit code it shows for PollProof.</li>
                        </ol>
                        <details class="text-xs text-ink-2"><summary class="cursor-pointer">Can't scan? Type this key instead</summary><code class="mt-1 block font-mono text-sm break-all text-ink">{{ setup.secret.match(/.{1,4}/g).join(' ') }}</code></details>
                        <label class="label !mb-0" for="otp">Code from the app</label>
                        <input id="otp" v-model="code" class="field w-40 text-center font-mono text-xl tracking-[0.3em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required />
                        <button class="btn-primary self-start" :disabled="busy">Turn on</button>
                    </form>
                </div>

                <div v-if="step === 'codes'" class="mt-5">
                    <h3 class="text-sm font-bold">Save your recovery codes</h3>
                    <p class="mt-1 text-sm text-ink-2">If you lose your phone, each of these lets you sign in once. They won't be shown again.</p>
                    <ul class="mt-3 grid grid-cols-2 gap-2 rounded-xl bg-mist p-4 font-mono text-[15px] sm:grid-cols-4">
                        <li v-for="c in codes" :key="c">{{ c }}</li>
                    </ul>
                    <div class="mt-3 flex gap-2">
                        <button class="btn-outline" @click="download">Download</button>
                        <button class="btn-navy" @click="finish">I've saved them</button>
                    </div>
                </div>

                <p v-if="error" role="alert" class="mt-4 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            </section>

            <section class="card p-6">
                <h2 class="card-title ">Password</h2>
                <p class="mt-1 text-sm text-ink-2">Changing it signs you out on other devices.</p>
                <RouterLink to="/account/password" class="btn-outline mt-4">Change password</RouterLink>
            </section>
        </div>
    </LoadState>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import { api } from '../lib/api';
import { loadSession, session } from '../lib/session';
import { homeFor } from '../lib/nav';

const router = useRouter();
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const errors = ref({});
const busy = ref(false);
const done = ref(false);
const forced = session.user?.must_change_password;

async function submit() {
    busy.value = true;
    errors.value = {};
    try {
        await api.post('me/password', { ...form });
        done.value = true;
        await loadSession();
        if (forced) router.replace(session.user.two_factor_required ? { name: 'security' } : homeFor(session.user.role));
    } catch (e) {
        errors.value = e.errors && Object.keys(e.errors).length ? e.errors : { password: [e.message] };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Change password" :subtitle="forced ? 'You signed in with a temporary password. Choose your own before you continue.' : 'Other devices signed in as you will be signed out.'" />
    <form class="card flex max-w-md flex-col gap-4 p-6" @submit.prevent="submit">
        <p v-if="done" role="status" class="rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">Password changed.</p>
        <div>
            <label class="label" for="cur">{{ forced ? 'Temporary password' : 'Current password' }}</label>
            <input id="cur" v-model="form.current_password" type="password" autocomplete="current-password" class="field" required />
            <p v-if="errors.current_password" class="mt-1 text-xs text-bad">{{ errors.current_password[0] }}</p>
        </div>
        <div>
            <label class="label" for="new">New password</label>
            <input id="new" v-model="form.password" type="password" autocomplete="new-password" class="field" minlength="10" required aria-describedby="new-help" />
            <p id="new-help" class="mt-1 text-xs text-ink-2">At least 10 characters, with letters and numbers.</p>
            <p v-if="errors.password" class="mt-1 text-xs text-bad">{{ errors.password[0] }}</p>
        </div>
        <div>
            <label class="label" for="confirm">Type it again</label>
            <input id="confirm" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" required />
        </div>
        <button class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Change password' }}</button>
    </form>
</template>

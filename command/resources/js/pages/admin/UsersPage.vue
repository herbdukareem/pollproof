<script setup>
import { computed, reactive, ref } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { dateTime } from '../../lib/format';

const users = useResource(() => api.get('users'));
const d = computed(() => users.data.value);
const search = ref('');
const rows = computed(() => (d.value?.data ?? []).filter((u) => !search.value || `${u.name} ${u.email} ${u.role_label}`.toLowerCase().includes(search.value.toLowerCase())));
const form = reactive({ open: false, name: '', email: '', role: 'situation_room' });
const secret = ref(null);
const busy = ref('');
const error = ref('');
const copied = ref(false);

// What each role can do; mirrors the API's route roles.
const perms = ['View tallies', 'Verify results', 'Enter data', 'Evidence and audit', 'See agent phones', 'Export payments', 'Manage users'];
const matrix = { dg: '1111111', situation_room: '1110100', verifier: '1100000', data_officer: '1010000', legal: '1001000', finance: '0000010' };

async function act(key, fn) {
    busy.value = key;
    error.value = '';
    try {
        await fn();
        await users.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}

const add = () => act('add', async () => {
    const res = await api.post('users', { name: form.name, email: form.email, role: form.role });
    secret.value = { who: form.name, email: form.email, password: res.temporary_password };
    Object.assign(form, { open: false, name: '', email: '' });
});
const setRole = (u, role) => act(`r${u.id}`, () => api.patch(`users/${u.id}`, { role }));
const resetTwoFactor = (u) => act(`t${u.id}`, () => api.post(`users/${u.id}/reset-two-factor`, {}));
const toggle = (u) => act(`a${u.id}`, () => api.patch(`users/${u.id}`, { active: !u.active }));
const reset = (u) => act(`p${u.id}`, async () => {
    const res = await api.post(`users/${u.id}/reset-password`, {});
    secret.value = { who: u.name, email: u.email, password: res.temporary_password };
});

async function copy() {
    try {
        await navigator.clipboard.writeText(secret.value.password);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <PageHeader title="Users and roles" :subtitle="d ? `${d.data.filter((u) => u.active).length} command-room users · ${d.data.filter((u) => u.active && u.two_factor).length} with two-step sign-in. Field staff are managed in the roster and supervisor screens.` : ''" :updated-at="users.updatedAt.value" :loading="users.loading.value" @refresh="users.load">
        <template #actions>
            <button class="btn-primary" @click="form.open = !form.open"><Icon name="plus" :size="16" /> Add user</button>
        </template>
    </PageHeader>

    <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

    <section v-if="secret" class="mb-5 rounded-2xl border-2 border-navy bg-white p-5" aria-live="polite">
        <h2 class="text-[15px] font-bold">Temporary password for {{ secret.who }}</h2>
        <p class="mt-1 text-sm text-ink-2">Give it to them in person or by phone. It is shown only once, and they must choose their own password when they first sign in with {{ secret.email }}.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <code class="rounded-xl bg-mist px-4 py-2.5 font-mono text-lg font-bold tracking-wider">{{ secret.password }}</code>
            <button class="btn-outline" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
            <button class="btn-navy" @click="secret = null">Done</button>
        </div>
    </section>

    <form v-if="form.open" class="card mb-5 grid gap-3 p-5 md:grid-cols-[1fr_1fr_220px_auto]" @submit.prevent="add">
        <div><label class="label" for="u-name">Name</label><input id="u-name" v-model="form.name" class="field" required /></div>
        <div><label class="label" for="u-email">Email</label><input id="u-email" v-model="form.email" type="email" class="field" required /></div>
        <div>
            <label class="label" for="u-role">Role</label>
            <select id="u-role" v-model="form.role" class="field"><option v-for="r in d?.roles ?? []" :key="r.value" :value="r.value">{{ r.label }}</option></select>
        </div>
        <div class="flex items-end"><button class="btn-navy h-11 w-full" :disabled="busy === 'add'">Add user</button></div>
    </form>

    <LoadState :loading="users.loading.value && !d" :error="users.error.value" :empty="!d">
        <section class="card mb-5 overflow-hidden">
            <div class="px-5 pt-5 pb-3">
                <h2 class="card-title ">What each role can do</h2>
                <p class="text-[13px] text-ink-2">Agent phone numbers and payment lists are limited to the roles that need them.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-mist"><tr><th class="th">Role</th><th v-for="p in perms" :key="p" class="th text-center">{{ p }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.roles" :key="r.value">
                            <td class="td"><b>{{ r.label }}</b> <span class="text-xs text-ink-2">· {{ r.count }} {{ r.count === 1 ? 'user' : 'users' }}</span></td>
                            <td v-for="(p, i) in perms" :key="p" class="td text-center">
                                <span v-if="matrix[r.value][i] === '1'" class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-good-bg text-good"><Icon name="check" :size="14" /><span class="sr-only">Yes</span></span>
                                <span v-else class="text-ink-3" aria-label="No">–</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                <h2 class="card-title ">People</h2>
                <input v-model="search" class="field h-10 max-w-xs" placeholder="Search users" aria-label="Search users" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-sm">
                    <thead class="bg-mist"><tr><th class="th">User</th><th class="th">Role</th><th class="th">Status</th><th class="th">Two-step</th><th class="th">Last active</th><th class="th"></th></tr></thead>
                    <tbody>
                        <tr v-for="u in rows" :key="u.id" :class="u.active ? '' : 'opacity-60'">
                            <td class="td">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-navy text-xs font-bold text-white" aria-hidden="true">{{ u.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase() }}</span>
                                    <span><span class="block font-bold">{{ u.name }}<span v-if="u.is_me" class="font-medium text-ink-2"> (you)</span></span><span class="text-xs text-ink-2">{{ u.email }}</span></span>
                                </div>
                            </td>
                            <td class="td">
                                <label class="sr-only" :for="`role-${u.id}`">Role for {{ u.name }}</label>
                                <select :id="`role-${u.id}`" class="field h-9 w-44 text-[13px]" :value="u.role" :disabled="u.is_me || !u.active || busy === `r${u.id}`" @change="setRole(u, $event.target.value)">
                                    <option v-for="r in d.roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                                </select>
                            </td>
                            <td class="td">
                                <Badge v-if="!u.active" tone="neutral">Deactivated</Badge>
                                <Badge v-else-if="u.must_change_password" tone="warn">Temporary password</Badge>
                                <Badge v-else tone="good">Active</Badge>
                            </td>
                            <td class="td"><Badge :tone="u.two_factor ? 'good' : 'bad'">{{ u.two_factor ? 'On' : 'Not set up' }}</Badge></td>
                            <td class="td text-ink-2">{{ u.last_active_at ? dateTime(u.last_active_at) : 'Never' }}</td>
                            <td class="td">
                                <div v-if="!u.is_me" class="flex justify-end gap-2">
                                    <button v-if="u.active && u.two_factor" class="btn-outline h-8 px-3 text-xs" :disabled="busy === `t${u.id}`" title="For a lost phone: they set it up again at next sign-in" @click="resetTwoFactor(u)">Reset two-step</button>
                                    <button v-if="u.active" class="btn-outline h-8 px-3 text-xs" :disabled="busy === `p${u.id}`" @click="reset(u)">Reset password</button>
                                    <button class="btn-outline h-8 px-3 text-xs" :disabled="busy === `a${u.id}`" @click="toggle(u)">{{ u.active ? 'Deactivate' : 'Reactivate' }}</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </LoadState>
</template>

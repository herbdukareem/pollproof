<script setup>
import { computed, reactive, ref } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';

const rules = useResource(() => api.get('notification-rules'));
const d = computed(() => rules.data.value);
const roleLabel = computed(() => Object.fromEntries((d.value?.roles ?? []).map((r) => [r.value, r.label])));
const editing = ref(null);
const draft = reactive({ recipients: [], channels: [], escalate_after_minutes: null, escalate_to: [], enabled: true });
const busy = ref('');
const message = ref('');
const error = ref('');
const channels = [['app', 'In-app'], ['sms', 'SMS'], ['email', 'Email']];

function edit(r) {
    editing.value = r.id;
    Object.assign(draft, JSON.parse(JSON.stringify({ recipients: r.recipients, channels: r.channels, escalate_after_minutes: r.escalate_after_minutes, escalate_to: r.escalate_to, enabled: r.enabled })));
}
function toggle(list, value) {
    const i = list.indexOf(value);
    i === -1 ? list.push(value) : list.splice(i, 1);
}
async function save(r) {
    busy.value = `s${r.id}`;
    error.value = message.value = '';
    try {
        rules.data.value = await api.patch(`notification-rules/${r.id}`, { ...draft, escalate_after_minutes: draft.escalate_after_minutes || null });
        editing.value = null;
        message.value = `Saved "${r.label}".`;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}
async function test() {
    busy.value = 'test';
    error.value = message.value = '';
    try {
        const res = await api.post('notification-rules/test', {});
        message.value = `Sent ${res.sent} test alerts to you. Check the bell, your phone and your email.`;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}
const names = (list) => (list?.length ? list.map((r) => roleLabel.value[r] ?? r).join(', ') : 'Nobody');
</script>

<template>
    <PageHeader title="Notifications" subtitle="Who hears about what, and how, on election day." :updated-at="rules.updatedAt.value" :loading="rules.loading.value" @refresh="rules.load" />
    <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

    <LoadState :loading="rules.loading.value && !d" :error="rules.error.value" :empty="!d">
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
            <section class="card overflow-hidden">
                <div class="border-b border-rule px-5 pt-4 pb-3">
                    <h2 class="card-title">Alert rules</h2>
                    <p class="card-sub">Supervisors and collation agents only hear about their own LGA or ward. "If no one acts" means the alert is still open, not acknowledged, after that time.</p>
                </div>
                <ul>
                    <li v-for="r in d.data" :key="r.id" class="border-t border-rule px-5 py-3.5 first:border-t-0" :class="r.enabled ? '' : 'opacity-60'">
                        <div v-if="editing !== r.id" class="grid items-center gap-3 md:grid-cols-[1.3fr_1.4fr_auto_1fr_auto]">
                            <div class="text-sm font-[620]">{{ r.label }}<Badge v-if="!r.enabled" tone="neutral" class="ml-2">Off</Badge></div>
                            <div class="text-[13px] text-ink-2">{{ names(r.recipients) }}</div>
                            <div class="flex gap-1">
                                <span v-for="[key, label] in channels" :key="key" class="rounded px-1.5 py-px text-[12px] font-[600]" :class="r.channels.includes(key) ? 'bg-[#e5ecf8] text-[#1d3f7a]' : 'text-ink-3 line-through decoration-ink-3/50'">{{ label }}</span>
                            </div>
                            <div class="text-[13px]" :class="r.escalate_after_minutes ? '' : 'text-ink-3'">{{ r.escalate_after_minutes ? `${names(r.escalate_to)} after ${r.escalate_after_minutes} min` : 'No escalation' }}</div>
                            <button class="btn-ghost h-8 px-3 text-[13px]" @click="edit(r)">Edit</button>
                        </div>
                        <form v-else class="flex flex-col gap-4" @submit.prevent="save(r)">
                            <div class="flex items-center justify-between"><b class="text-sm font-[650]">{{ r.label }}</b><label class="flex items-center gap-2 text-sm"><input v-model="draft.enabled" type="checkbox" class="checkbox" /> On</label></div>
                            <fieldset>
                                <legend class="label">Tell</legend>
                                <div class="flex flex-wrap gap-2">
                                    <label v-for="role in d.roles" :key="role.value" class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px]" :class="draft.recipients.includes(role.value) ? 'border-navy bg-[#f3f5f8] font-[600]' : 'border-line'">
                                        <input type="checkbox" class="checkbox" :checked="draft.recipients.includes(role.value)" @change="toggle(draft.recipients, role.value)" /> {{ role.label }}
                                    </label>
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend class="label">By</legend>
                                <div class="flex gap-2">
                                    <label v-for="[key, label] in channels" :key="key" class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px]" :class="draft.channels.includes(key) ? 'border-navy bg-[#f3f5f8] font-[600]' : 'border-line'">
                                        <input type="checkbox" class="checkbox" :checked="draft.channels.includes(key)" @change="toggle(draft.channels, key)" /> {{ label }}
                                    </label>
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend class="label">If no one acts</legend>
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    after <input v-model.number="draft.escalate_after_minutes" type="number" min="1" max="1440" class="field h-9 w-20" aria-label="Minutes" placeholder="–" /> minutes, tell
                                    <label v-for="role in d.roles" :key="role.value" class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px]" :class="draft.escalate_to.includes(role.value) ? 'border-action bg-[#fdf5ef] font-[600]' : 'border-line'">
                                        <input type="checkbox" class="checkbox accent-[#c2410c]" :checked="draft.escalate_to.includes(role.value)" @change="toggle(draft.escalate_to, role.value)" /> {{ role.label }}
                                    </label>
                                </div>
                            </fieldset>
                            <div class="flex gap-2"><button class="btn-navy" :disabled="busy === `s${r.id}`">Save</button><button type="button" class="btn-outline" @click="editing = null">Cancel</button></div>
                        </form>
                    </li>
                </ul>
            </section>

            <div class="flex flex-col gap-4">
                <section class="card p-5">
                    <h2 class="card-title-ruled">Delivery channels</h2>
                    <dl class="flex flex-col gap-2.5 text-sm">
                        <div class="flex items-baseline gap-2.5"><span class="h-2 w-2 shrink-0 rounded-full bg-good"></span><dt class="w-14 font-[620]">In-app</dt><dd class="text-ink-2">The bell, and the agent app</dd></div>
                        <div class="flex items-baseline gap-2.5"><span class="h-2 w-2 shrink-0 rounded-full" :class="d.sms_driver === 'log' ? 'bg-[#d99a00]' : 'bg-good'"></span><dt class="w-14 font-[620]">SMS</dt><dd class="text-ink-2">{{ d.sms_driver === 'log' ? 'Test mode, written to the log' : d.sms_driver.charAt(0).toUpperCase() + d.sms_driver.slice(1) }}</dd></div>
                        <div class="flex items-baseline gap-2.5"><span class="h-2 w-2 shrink-0 rounded-full" :class="['log', 'array'].includes(d.mail_driver) ? 'bg-[#d99a00]' : 'bg-good'"></span><dt class="w-14 font-[620]">Email</dt><dd class="text-ink-2">{{ ['log', 'array'].includes(d.mail_driver) ? 'Test mode, written to the log' : d.mail_driver.toUpperCase() }}</dd></div>
                    </dl>
                </section>
                <section class="card p-5">
                    <h2 class="card-title-ruled">Test before election day</h2>
                    <p class="text-[13px] text-ink-2">Send one sample of every alert to yourself to check the wording and that it arrives.</p>
                    <button class="btn-primary mt-3 w-full" :disabled="busy === 'test'" @click="test"><Icon name="send" :size="16" /> Send test alerts to me</button>
                </section>
            </div>
        </div>
    </LoadState>
</template>

<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { dateTime, num, time } from '../lib/format';

const filter = ref('');
const agents = useResource(() => api.get('agents'), { refreshMs: 30000 });
const pending = useResource(() => api.get('check-ins'), { refreshMs: 30000 });

const summary = computed(() => agents.data.value?.summary ?? {});
const rows = computed(() => (agents.data.value?.data ?? []).filter((a) => !filter.value || a.status === filter.value));
const reserves = computed(() => (agents.data.value?.data ?? []).filter((a) => a.status === 'reserve'));
const busy = ref(null);
const message = ref('');
const error = ref('');
const dispatch = ref({});

const tone = { present: 'good', absent: 'bad', outside_area: 'warn', reserve: 'info' };
const label = { present: 'Present', absent: 'Absent', outside_area: 'Outside area', reserve: 'Reserve' };
const tiles = [
    ['present', 'Present at their unit', 'bg-good'],
    ['absent', 'Absent', 'bg-action'],
    ['outside_area', 'Checked in outside area', 'bg-[#d99a00]'],
    ['reserve', 'Reserves on standby', 'bg-[#1c6fc2]'],
];

async function review(c, decision) {
    busy.value = `c${c.id}`;
    error.value = '';
    try {
        await api.post(`check-ins/${c.id}/review`, { decision });
        message.value = `${decision === 'approve' ? 'Approved' : 'Rejected'} check-in for ${c.polling_unit.code}.`;
        await Promise.all([pending.load(), agents.load()]);
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = null;
    }
}

async function send(agent) {
    const reserveId = dispatch.value[agent.id];
    if (!reserveId) return;
    busy.value = `a${agent.id}`;
    error.value = '';
    try {
        await api.post(`agents/${reserveId}/assign`, { polling_unit_id: agent.polling_unit.id });
        message.value = `Reserve sent to ${agent.polling_unit.code}.`;
        await agents.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageHeader title="Agents" subtitle="Who is at their polling unit, who needs replacing, and check-ins to confirm." :updated-at="agents.updatedAt.value" :loading="agents.loading.value" @refresh="() => { agents.load(); pending.load(); }" />
    <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
    <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button v-for="[key, text, bar] in tiles" :key="key" class="card flex items-center gap-4 p-4 text-left" :class="filter === key ? 'ring-2 ring-navy' : ''" :aria-pressed="filter === key" @click="filter = filter === key ? '' : key">
            <span class="h-11 w-2.5 rounded-full" :class="bar"></span>
            <span><span class="block text-[26px] leading-none font-extrabold">{{ num(summary[key] ?? 0) }}</span><span class="text-[13px] text-ink-2">{{ text }}</span></span>
        </button>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_360px]">
        <section class="card overflow-hidden">
            <LoadState :loading="agents.loading.value" :error="agents.error.value" :empty="!rows.length" empty-text="No agents in this group.">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">Agent</th><th class="th">Polling unit</th><th class="th">Check-in</th><th class="th">Status</th><th class="th">Result</th><th class="th"></th></tr></thead>
                        <tbody>
                            <tr v-for="a in rows" :key="a.id">
                                <td class="td"><div class="font-bold">{{ a.code }}</div><div class="text-xs text-ink-2">{{ a.name }}<template v-if="a.phone"> · {{ a.phone }}</template></div></td>
                                <td class="td"><template v-if="a.polling_unit"><div class="font-semibold">{{ a.polling_unit.code }}</div><div class="text-xs text-ink-2">{{ a.polling_unit.ward }} · {{ a.polling_unit.lga }}</div></template><span v-else class="text-ink-2">–</span></td>
                                <td class="td text-ink-2">{{ a.check_in ? `${time(a.check_in.at)}${a.check_in.distance_m != null ? ' · ' + a.check_in.distance_m + ' m' : ''}` : '–' }}</td>
                                <td class="td"><Badge :tone="tone[a.status]">{{ label[a.status] }}</Badge></td>
                                <td class="td text-ink-2">{{ a.result ? `Sent ${time(a.result.at)}` : '–' }}</td>
                                <td class="td">
                                    <div v-if="a.status === 'absent' && reserves.length" class="flex items-center gap-2">
                                        <label class="sr-only" :for="`r-${a.id}`">Reserve for {{ a.polling_unit?.code }}</label>
                                        <select :id="`r-${a.id}`" v-model="dispatch[a.id]" class="field h-9 w-36 text-xs">
                                            <option :value="undefined">Choose reserve</option>
                                            <option v-for="r in reserves" :key="r.id" :value="r.id">{{ r.code }}</option>
                                        </select>
                                        <button class="btn-primary h-9 px-3 text-xs" :disabled="!dispatch[a.id] || busy === `a${a.id}`" @click="send(a)">Send</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </LoadState>
        </section>

        <section class="card self-start p-5">
            <h2 class="text-[17px] font-bold">Check-ins to confirm</h2>
            <p class="mt-1 mb-3 text-[13px] text-ink-2">Agents who checked in outside the unit area and say they're there.</p>
            <LoadState :loading="pending.loading.value" :error="pending.error.value" :empty="!(pending.data.value?.data ?? []).length" empty-text="Nothing to confirm.">
                <ul class="flex flex-col gap-2">
                    <li v-for="c in pending.data.value.data" :key="c.id" class="rounded-xl bg-mist p-3.5">
                        <div class="flex justify-between gap-2 text-sm"><b>{{ c.polling_unit.code }}</b><span class="text-xs text-ink-2">{{ dateTime(c.captured_at) }}</span></div>
                        <p class="mt-0.5 text-[13px] text-ink-2">{{ c.agent.code }} · {{ c.distance_m != null ? `${c.distance_m} m away` : 'unit has no GPS point' }} · accuracy {{ c.accuracy_m }} m{{ c.has_selfie ? ' · selfie attached' : '' }}</p>
                        <div class="mt-2.5 flex gap-2">
                            <button class="btn-good h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'approve')">Approve</button>
                            <button class="btn-outline h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'reject')">Reject</button>
                        </div>
                    </li>
                </ul>
            </LoadState>
        </section>
    </div>
</template>

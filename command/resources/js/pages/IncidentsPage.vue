<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, titleCase } from '../lib/format';

const status = ref('open');
const incidents = useResource(() => api.get('incidents', { status: status.value }), { refreshMs: 30000 });
watch(status, () => incidents.load());

const all = computed(() => incidents.data.value?.data ?? []);
const type = ref('');
const rows = computed(() => (type.value ? all.value.filter((i) => i.type === type.value) : all.value));
const typeCounts = computed(() => {
    const out = {};
    for (const i of all.value) out[i.type] = (out[i.type] ?? 0) + 1;
    return out;
});
watch(status, () => (type.value = ''));
const selectedId = ref(null);
const selected = computed(() => rows.value.find((i) => i.id === selectedId.value) ?? rows.value[0] ?? null);
const canEdit = hasRole('situation_room', 'dg', 'legal');
const note = ref('');
const assign = ref('');
const busy = ref(false);
const error = ref('');

watch(selected, (s) => { note.value = ''; assign.value = s?.assigned_to ?? ''; error.value = ''; });

async function update(fields) {
    busy.value = true;
    error.value = '';
    try {
        await api.patch(`incidents/${selected.value.id}`, fields);
        note.value = '';
        await incidents.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

const typeLabel = { disruption: 'Disruption', vote_buying: 'Vote buying', bvas_problem: 'BVAS problem', agent_turned_away: 'Agent turned away', materials_late: 'Materials late', other: 'Other' };
const sevTone = { high: 'bad', medium: 'warn', low: 'neutral' };
const statusTone = { open: 'warn', handling: 'info', resolved: 'good' };
</script>

<template>
    <PageHeader title="Incidents" subtitle="Reports from agents, with who is handling each one." :updated-at="incidents.updatedAt.value" :loading="incidents.loading.value" @refresh="incidents.load">
        <template #actions>
            <div class="flex rounded-xl border border-[#d6dae1] bg-white p-1" role="group" aria-label="Status">
                <button v-for="s in ['open', 'handling', 'resolved']" :key="s" class="btn h-8 px-3" :class="status === s ? 'bg-navy text-white' : 'text-ink'" :aria-pressed="status === s" @click="status = s">{{ titleCase(s) }}</button>
            </div>
        </template>
    </PageHeader>

    <div v-if="all.length" class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Type">
        <button class="chip h-9" :class="type === '' ? 'chip-on' : ''" :aria-pressed="type === ''" @click="type = ''">All <b>{{ all.length }}</b></button>
        <button v-for="(n, t) in typeCounts" :key="t" class="chip h-9" :class="type === t ? 'chip-on' : ''" :aria-pressed="type === t" @click="type = t">{{ typeLabel[t] }} <b>{{ n }}</b></button>
    </div>

    <LoadState :loading="incidents.loading.value" :error="incidents.error.value" :empty="!rows.length" :empty-text="`No ${status} incidents.`">
        <div class="grid items-start gap-5 lg:grid-cols-[380px_1fr]">
            <section class="card flex max-h-[calc(100vh-220px)] flex-col gap-1 overflow-y-auto p-2" aria-label="Incident list">
                <button v-for="i in rows" :key="i.id" class="flex gap-3 rounded-xl border-2 px-3.5 py-3 text-left transition" :class="selected?.id === i.id ? 'border-navy bg-white shadow-sm' : 'border-transparent hover:bg-mist'" @click="selectedId = i.id">
                    <span class="w-1 shrink-0 rounded-full" :class="{ high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' }[i.severity]"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex justify-between gap-2"><b class="text-sm">{{ typeLabel[i.type] }}</b><span class="text-xs whitespace-nowrap text-ink-2">{{ dateTime(i.occurred_at) }}</span></span>
                        <span class="block truncate text-xs text-ink-2">{{ i.polling_unit ? `${i.polling_unit.code} · ${i.polling_unit.name}` : 'No unit' }} · {{ i.user?.code }}</span>
                        <span class="mt-1.5 flex gap-1.5"><Badge :tone="sevTone[i.severity]">{{ i.severity }}</Badge><Badge :tone="statusTone[i.status]">{{ i.status }}</Badge></span>
                    </span>
                </button>
            </section>

            <section v-if="selected" class="card p-5">
                <div class="flex flex-wrap gap-2"><Badge :tone="sevTone[selected.severity]">{{ selected.severity }}</Badge><Badge :tone="statusTone[selected.status]">{{ selected.status }}</Badge></div>
                <h2 class="mt-3 text-[22px] font-extrabold tracking-tight">{{ typeLabel[selected.type] }}</h2>
                <p class="mt-1 text-sm text-ink-2">{{ selected.polling_unit ? `${selected.polling_unit.code} · ${selected.polling_unit.name}` : 'No unit' }} · reported by {{ selected.user?.name }} ({{ selected.user?.code }}) · {{ dateTime(selected.occurred_at) }}</p>
                <div v-if="selected.media_paths?.length" class="mt-4 grid grid-cols-3 gap-2.5">
                    <div v-for="(m, k) in selected.media_paths" :key="k" class="flex h-24 flex-col items-center justify-center gap-1 rounded-xl bg-[#e4e8ee] text-xs text-ink-2"><Icon name="image" :size="20" />Photo {{ k + 1 }}</div>
                </div>
                <blockquote class="mt-4 rounded-xl border-l-4 border-navy bg-mist p-4 text-[15px] leading-relaxed">“{{ selected.description }}”</blockquote>
                <p v-if="selected.assigned_to" class="mt-3 text-sm"><span class="text-ink-2">Assigned to</span> <b>{{ selected.assigned_to }}</b></p>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-bold">Updates</h3>
                    <p v-if="!selected.updates?.length" class="text-sm text-ink-2">No updates yet.</p>
                    <ol class="relative ml-1.5 border-l-2 border-line">
                        <li v-for="(u, k) in selected.updates" :key="k" class="relative pb-3 pl-5 text-sm last:pb-0">
                            <span class="absolute top-1 -left-[7px] h-3 w-3 rounded-full border-2 border-white bg-navy"></span>
                            <span class="block text-xs text-ink-2">{{ dateTime(u.at) }} · {{ u.by }}</span>{{ u.text }}
                        </li>
                    </ol>
                </div>

                <template v-if="canEdit">
                    <div class="mt-5 grid gap-3 sm:grid-cols-[220px_1fr]">
                        <div>
                            <label class="label" for="assign">Assigned to</label>
                            <input id="assign" v-model="assign" class="field" placeholder="e.g. LGA I supervisor" />
                        </div>
                        <div>
                            <label class="label" for="note">Add an update</label>
                            <input id="note" v-model="note" class="field" placeholder="What happened next" />
                        </div>
                    </div>
                    <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button class="btn-navy" :disabled="busy" @click="update({ status: 'handling', assigned_to: assign || null, note: note || null })">Save as being handled</button>
                        <button class="btn-good" :disabled="busy" @click="update({ status: 'resolved', note: note || null })">Mark resolved</button>
                    </div>
                </template>
            </section>
        </div>
    </LoadState>
</template>

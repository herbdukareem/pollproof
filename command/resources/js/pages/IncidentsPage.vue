<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, titleCase } from '../lib/format';

const status = ref('open');
const incidents = useResource(() => api.get('incidents', { status: status.value }), { refreshMs: 30000 });
watch(status, () => incidents.load());

const rows = computed(() => incidents.data.value?.data ?? []);
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

    <LoadState :loading="incidents.loading.value" :error="incidents.error.value" :empty="!rows.length" :empty-text="`No ${status} incidents.`">
        <div class="grid gap-5 lg:grid-cols-[360px_1fr]">
            <section class="card flex flex-col gap-1 p-2" aria-label="Incident list">
                <button v-for="i in rows" :key="i.id" class="flex gap-3 rounded-xl border-2 px-3.5 py-3 text-left" :class="selected?.id === i.id ? 'border-navy bg-mist' : 'border-transparent hover:bg-mist'" @click="selectedId = i.id">
                    <span class="w-1 shrink-0 rounded-full" :class="{ high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' }[i.severity]"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex justify-between gap-2"><b class="text-sm">{{ typeLabel[i.type] }}</b><span class="text-xs text-ink-2">{{ dateTime(i.occurred_at) }}</span></span>
                        <span class="block truncate text-xs text-ink-2">{{ i.polling_unit?.code ?? 'No unit' }} · {{ i.user?.code }}</span>
                    </span>
                </button>
            </section>

            <section v-if="selected" class="card p-5">
                <div class="flex flex-wrap gap-2"><Badge :tone="sevTone[selected.severity]">{{ selected.severity }}</Badge><Badge :tone="statusTone[selected.status]">{{ selected.status }}</Badge></div>
                <h2 class="mt-3 text-xl font-extrabold">{{ typeLabel[selected.type] }}</h2>
                <p class="mt-1 text-sm text-ink-2">{{ selected.polling_unit ? `${selected.polling_unit.code} · ${selected.polling_unit.name}` : 'No unit' }} · reported by {{ selected.user?.name }} ({{ selected.user?.code }}) · {{ dateTime(selected.occurred_at) }}</p>
                <blockquote class="mt-4 rounded-xl bg-mist p-4 text-[15px] leading-relaxed">{{ selected.description }}</blockquote>
                <p v-if="selected.media_paths?.length" class="mt-2 text-xs text-ink-2">{{ selected.media_paths.length }} media file(s) attached</p>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-bold">Updates</h3>
                    <p v-if="!selected.updates?.length" class="text-sm text-ink-2">No updates yet.</p>
                    <ul>
                        <li v-for="(u, k) in selected.updates" :key="k" class="flex gap-3 py-1.5 text-sm"><span class="w-28 shrink-0 text-ink-2">{{ dateTime(u.at) }}</span><span><b>{{ u.by }}:</b> {{ u.text }}</span></li>
                    </ul>
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

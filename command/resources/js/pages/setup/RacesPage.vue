<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import RaceTag from '../../components/RaceTag.vue';
import RaceEditor from '../../components/RaceEditor.vue';
import ActionMenu from '../../components/ActionMenu.vue';
import Icon from '../../components/Icon.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { hasRole } from '../../lib/session';
import { num } from '../../lib/format';
import { toast } from '../../lib/toast';
import { byDate, fmtRaceDate, loadRaces, races, ruleText, selectRace } from '../../lib/races';
import { useRouter } from 'vue-router';

/** Setup: what is on the ballot, where, and on which day. */
const router = useRouter();
const canEdit = hasRole('dg');
const list = useResource(() => loadRaces(true));
const structure = useResource(() => api.get('structure'));
const board = useResource(() => api.get('tally/board').catch(() => ({ races: [] })));
const units = computed(() => Object.fromEntries((board.data.value?.races ?? []).map((r) => [r.id, r])));

const editing = ref(null);
const open = ref(false);
function add() {
    editing.value = null;
    open.value = true;
}
function edit(r) {
    editing.value = r;
    open.value = true;
}
async function saved(r) {
    const isNew = !editing.value;
    open.value = false;
    await loadRaces(true);
    board.load();
    toast({ icon: 'check', tone: 'good', title: isNew ? `${r.name} added` : `${r.name} saved`, body: isNew ? 'Agents in its area see it on their ballot the next time their app syncs.' : undefined });
}
async function remove(r) {
    if (!window.confirm(`Remove ${r.name}? Agents will no longer see it.`)) return;
    try {
        await api.delete(`elections/${r.id}`);
        await loadRaces(true);
        toast({ icon: 'check', tone: 'good', title: `${r.name} removed` });
    } catch (e) {
        toast({ icon: 'alert', tone: 'bad', title: 'Could not remove the race', body: e.message });
    }
}
function open_(r) {
    selectRace(r.id);
    router.push('/overview');
}
const ballot = (r) => {
    const ours = r.parties.find((p) => p.is_ours);
    return `${r.parties.length} parties${ours?.candidate ? `, our candidate ${ours.candidate}` : ''}`;
};
const subtitle = computed(() => {
    const n = races.list.length;
    const days = byDate.value.length;
    return n ? `${n} race${n === 1 ? '' : 's'}${days > 1 ? ` across ${days} election days` : ''}. Each has its own ballot, results and declaration.` : 'What is on the ballot, where, and on which day';
});
</script>

<template>
    <PageHeader title="Races" :subtitle="subtitle">
        <template #actions>
            <button v-if="canEdit" class="btn-primary" :disabled="!structure.data.value" @click="add"><Icon name="plus" :size="16" /> Add a race</button>
        </template>
    </PageHeader>

    <LoadState :loading="list.loading.value" :error="races.error" :empty="!races.list.length" empty-text="No races yet.">
        <div class="flex flex-col gap-5">
            <section v-for="g in byDate" :key="g.date" class="card overflow-hidden">
                <header class="card-head">
                    <h2 class="card-title flex items-center gap-2">
                        {{ fmtRaceDate(g.date) }}
                        <span v-if="g.current" class="rounded bg-navy px-1.5 py-px text-[11px] font-[650] text-white">Next election day</span>
                    </h2>
                    <span class="text-[12.5px] text-ink-2">{{ g.list.length }} race{{ g.list.length === 1 ? '' : 's' }}, all on one visit to the polling unit</span>
                </header>
                <ul>
                    <li v-for="r in g.list" :key="r.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-rule px-5 py-4 first:border-t-0">
                        <RaceTag :race="r" size="lg" />
                        <div class="min-w-[220px] flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <button class="text-left text-[15.5px] font-[660] text-ink hover:underline" :title="`Open ${r.name} on the overview`" @click="open_(r)">{{ r.name }}</button>
                                <span class="fig text-[12.5px] font-[600] text-ink-3">{{ r.code }}</span>
                            </div>
                            <p class="mt-0.5 text-[13px] text-ink-2">{{ r.area }}<template v-if="units[r.id]">, {{ num(units[r.id].units) }} polling units</template></p>
                            <p class="mt-0.5 text-[12.5px] text-ink-3">{{ ruleText(r) }}. {{ ballot(r) }}.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span v-if="r.is_rerun" class="pill bg-warn-bg text-warn">Re-run</span>
                            <span v-if="r.declared_at" class="pill bg-mist text-ink-2"><Icon name="lock" :size="13" /> Declared</span>
                            <span v-else-if="r.has_results" class="pill bg-good-bg text-good">Results coming in</span>
                            <span v-if="r.announce" class="pill bg-mist text-ink-2" title="Its news is spoken aloud"><Icon name="volume" :size="13" /> Spoken</span>
                        </div>
                        <ActionMenu
                            v-if="canEdit"
                            :label="`Actions for ${r.name}`"
                            :items="[
                                { label: 'Change', icon: 'edit', run: () => edit(r), disabled: !!r.declared_at, hint: r.declared_at ? 'Declared races are locked' : null },
                                { label: 'Open on the overview', icon: 'grid', run: () => open_(r) },
                                { label: 'Remove', icon: 'x', danger: true, run: () => remove(r), disabled: r.has_results || races.list.length === 1, hint: r.has_results ? 'It has results recorded' : races.list.length === 1 ? 'A campaign needs one race' : null },
                            ]"
                        />
                    </li>
                </ul>
            </section>

            <aside class="note max-w-[72ch] leading-relaxed">
                <b class="text-ink">How agents report several races.</b>
                In the app they choose the race, then type that sheet. By text: <span class="fig font-[650] text-ink">R HOA-BIDA1 004 AC448 A214 B187 X6</span>, where the race code comes first; with one race at their unit they can leave it out. On USSD they pick the race from a menu.
            </aside>
        </div>
    </LoadState>

    <RaceEditor :open="open" :race="editing" :structure="structure.data.value" @close="open = false" @saved="saved" />
</template>

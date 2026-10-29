<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';

const route = useRoute();
const router = useRouter();
const cases = useResource(() => api.get('tribunal-cases'));
const current = ref(null);
const loading = ref(false);
const error = ref('');
const message = ref('');
const busy = ref('');

const newCase = reactive({ open: false, title: '', petitioner: '', our_role: 'respondent' });
const ground = reactive({ open: false, label: '', claim: '' });
const task = reactive({ title: '', owner_id: '', due_on: '' });
const milestone = reactive({ date: '', label: '' });
const editingGround = ref(null);
const draft = reactive({ answer: '', evidence_note: '', unit_codes: '', owner_id: '' });

async function open(id) {
    if (!id) return;
    loading.value = true;
    error.value = '';
    try {
        current.value = await api.get(`tribunal-cases/${id}`);
    } catch (e) {
        error.value = e.firstError();
    } finally {
        loading.value = false;
    }
}
watch(() => route.params.id, (id) => open(id), { immediate: true });
watch(() => cases.data.value, (list) => {
    if (!route.params.id && list?.length) router.replace({ name: 'tribunal-case', params: { id: (list.find((c) => c.status === 'open') ?? list[0]).id } });
});

const c = computed(() => current.value);
const ready = computed(() => c.value?.grounds.filter((g) => g.status === 'ready').length ?? 0);
const evidenceUnits = computed(() => {
    const seen = new Map();
    c.value?.grounds.forEach((g) => g.units.forEach((u) => seen.set(u.id, { ...u, ground: g.label })));
    return [...seen.values()];
});

async function act(key, fn, done = '') {
    busy.value = key;
    error.value = message.value = '';
    try {
        const res = await fn();
        if (res?.case) current.value = res;
        message.value = done;
        return res;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}

async function createCase() {
    const res = await act('case', () => api.post('tribunal-cases', { title: newCase.title, petitioner: newCase.petitioner, our_role: newCase.our_role }), 'Case created.');
    if (res) {
        Object.assign(newCase, { open: false, title: '', petitioner: '' });
        await cases.load();
        router.push({ name: 'tribunal-case', params: { id: res.case.id } });
    }
}
const addMilestone = () => act('m', () => api.patch(`tribunal-cases/${c.value.case.id}`, { milestones: [...(c.value.case.milestones ?? []), { ...milestone }] })).then(() => Object.assign(milestone, { date: '', label: '' }));
const removeMilestone = (i) => act('m', () => api.patch(`tribunal-cases/${c.value.case.id}`, { milestones: c.value.case.milestones.filter((_, k) => k !== i) }));
const setStatus = (status) => act('s', () => api.patch(`tribunal-cases/${c.value.case.id}`, { status }), status === 'closed' ? 'Case closed.' : 'Case reopened.').then(() => cases.load());
const addGround = () => act('g', () => api.post(`tribunal-cases/${c.value.case.id}/grounds`, { label: ground.label, claim: ground.claim })).then((r) => r && Object.assign(ground, { open: false, label: '', claim: '' }));
function editGround(g) {
    editingGround.value = g.id;
    Object.assign(draft, { answer: g.answer ?? '', evidence_note: g.evidence_note ?? '', unit_codes: g.units.map((u) => u.code).join(', '), owner_id: g.owner?.id ?? '' });
}
const saveGround = (g, extra = {}) => act(`g${g.id}`, () => api.patch(`tribunal-grounds/${g.id}`, {
    answer: draft.answer || null, evidence_note: draft.evidence_note || null, owner_id: draft.owner_id || null,
    unit_codes: draft.unit_codes.split(/[\s,]+/).filter(Boolean), ...extra,
}), 'Saved.').then((r) => r && (editingGround.value = null));
const markReady = (g, ready) => act(`r${g.id}`, () => api.patch(`tribunal-grounds/${g.id}`, { status: ready ? 'ready' : 'in_progress' }));
const addTask = () => act('t', () => api.post(`tribunal-cases/${c.value.case.id}/tasks`, { title: task.title, owner_id: task.owner_id || null, due_on: task.due_on || null })).then((r) => r && Object.assign(task, { title: '', owner_id: '', due_on: '' }));
const toggleTask = (t) => act(`t${t.id}`, () => api.patch(`tribunal-tasks/${t.id}`, { done: !t.done_at }));
const initials = (n) => (n || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
const fmtDate = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('en-NG', { day: 'numeric', month: 'short' }) : '');
</script>

<template>
    <PageHeader title="Tribunal workspace" :subtitle="c ? `${c.case.title} · we are the ${c.case.our_role}` : 'Petitions, our answers and the reply bundle for counsel.'">
        <template #actions>
            <select v-if="cases.data.value?.length > 1" class="field h-10 w-56" aria-label="Case" :value="route.params.id" @change="router.push({ name: 'tribunal-case', params: { id: $event.target.value } })">
                <option v-for="k in cases.data.value" :key="k.id" :value="k.id">{{ k.title }}{{ k.status === 'closed' ? ' (closed)' : '' }}</option>
            </select>
            <button class="btn-outline" @click="newCase.open = !newCase.open"><Icon name="plus" :size="16" /> New case</button>
            <a v-if="c" :href="api.url(`tribunal-cases/${c.case.id}/bundle`)" class="btn-primary"><Icon name="download" :size="16" /> Build reply bundle</a>
        </template>
    </PageHeader>

    <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
    <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

    <form v-if="newCase.open || (cases.data.value && !cases.data.value.length)" class="card mb-5 grid gap-3 p-5 md:grid-cols-[1.5fr_1fr_180px_auto]" @submit.prevent="createCase">
        <div><label class="label" for="t-title">Case</label><input id="t-title" v-model="newCase.title" class="field" placeholder="e.g. Party B v INEC and others" required /></div>
        <div><label class="label" for="t-pet">Petitioner</label><input id="t-pet" v-model="newCase.petitioner" class="field" required /></div>
        <div><label class="label" for="t-role">We are the</label><select id="t-role" v-model="newCase.our_role" class="field"><option value="respondent">Respondent</option><option value="petitioner">Petitioner</option></select></div>
        <div class="flex items-end"><button class="btn-navy h-11 w-full" :disabled="busy === 'case'">Create</button></div>
        <p v-if="!cases.data.value?.length" class="text-sm text-ink-2 md:col-span-4">No petition yet. When one is served, add it here and build the answer ground by ground.</p>
    </form>

    <section v-if="cases.data.value && !cases.data.value.length" class="mb-5 rounded-2xl bg-navy p-6 text-white">
        <h2 class="text-lg font-extrabold">Petition window: 21 days from the declaration</h2>
        <p class="mt-1 text-sm text-[#c9d1de]">When a petition is served, the workspace keeps everything counsel needs in one place.</p>
        <ol class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <li v-for="(step, k) in ['Add the case and who filed it', 'List each ground of the petition', 'Answer each ground from our signed records', 'Build the reply bundle for counsel']" :key="k" class="flex gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-bold">{{ k + 1 }}</span>
                <span class="text-sm leading-snug font-semibold">{{ step }}</span>
            </li>
        </ol>
    </section>

    <LoadState :loading="loading && !c" :empty="!c && !!route.params.id" empty-text="Case not found.">
        <template v-if="c">
            <section class="mb-5 rounded-2xl bg-navy p-5 text-white">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3"><Badge :tone="c.case.status === 'open' ? 'warn' : 'neutral'">{{ c.case.status }}</Badge><span class="text-sm text-[#c9d1de]">Petitioner: {{ c.case.petitioner }}</span></div>
                    <button class="rounded-lg border border-white/30 px-3 py-1.5 text-xs font-bold hover:bg-white/10" @click="setStatus(c.case.status === 'open' ? 'closed' : 'open')">{{ c.case.status === 'open' ? 'Close case' : 'Reopen case' }}</button>
                </div>
                <ol class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(m, i) in c.case.milestones ?? []" :key="i" class="group relative rounded-xl bg-navy-2 p-3">
                        <div class="text-xs text-[#ffb48a]">{{ m.date }}</div>
                        <div class="text-sm font-bold">{{ m.label }}</div>
                        <button class="absolute top-2 right-2 hidden text-[#c9d1de] group-hover:block" :aria-label="`Remove ${m.label}`" @click="removeMilestone(i)"><Icon name="x" :size="14" /></button>
                    </li>
                    <li class="rounded-xl border border-dashed border-white/30 p-3">
                        <form class="flex flex-col gap-1.5" @submit.prevent="addMilestone">
                            <input v-model="milestone.date" class="rounded-md bg-white/10 px-2 py-1 text-xs text-white placeholder:text-[#8d9bb3]" placeholder="Date, e.g. 3 Mar 2027" aria-label="Milestone date" required />
                            <input v-model="milestone.label" class="rounded-md bg-white/10 px-2 py-1 text-sm text-white placeholder:text-[#8d9bb3]" placeholder="What happens" aria-label="Milestone" required />
                            <button class="self-start text-xs font-bold text-[#ffb48a]">Add date</button>
                        </form>
                    </li>
                </ol>
            </section>

            <div class="grid items-start gap-5 xl:grid-cols-[1.5fr_1fr]">
                <section class="card p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="card-title ">Grounds in the petition, and our answer</h2>
                        <span class="text-sm" :class="ready === c.grounds.length && c.grounds.length ? 'font-semibold text-good' : 'text-ink-2'">{{ ready }} of {{ c.grounds.length }} answers ready</span>
                    </div>
                    <ul class="mt-4 flex flex-col gap-3">
                        <li v-for="g in c.grounds" :key="g.id" class="rounded-xl border p-4" :class="g.status === 'ready' ? 'border-line' : 'border-[#f2c9a8]'">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="text-[11.5px] font-bold tracking-wider text-ink-2 uppercase">{{ g.label }}</div>
                                <Badge :tone="g.status === 'ready' ? 'good' : 'warn'">{{ g.status === 'ready' ? 'Answer ready' : 'In progress' }}</Badge>
                            </div>
                            <p class="mt-1 text-[15px] font-bold">{{ g.claim }}</p>

                            <template v-if="editingGround !== g.id">
                                <p class="mt-2 text-sm"><b>Our records: </b><span :class="g.answer ? '' : 'text-ink-3'">{{ g.answer || 'No answer written yet.' }}</span></p>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-[12.5px] text-ink-2">
                                    <span v-if="g.evidence_note">{{ g.evidence_note }}</span>
                                    <RouterLink v-for="u in g.units" :key="u.id" :to="`/units/${u.id}/evidence`" class="rounded-md bg-mist px-1.5 py-0.5 font-semibold text-navy hover:underline">{{ u.code }}</RouterLink>
                                    <span v-if="g.owner" class="ml-auto flex items-center gap-1.5"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-navy text-[10px] font-bold text-white">{{ initials(g.owner.name) }}</span>{{ g.owner.name }}</span>
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <button class="btn-outline h-8 px-3 text-xs" @click="editGround(g)">Edit answer</button>
                                    <button class="btn-outline h-8 px-3 text-xs" :disabled="busy === `r${g.id}`" @click="markReady(g, g.status !== 'ready')">{{ g.status === 'ready' ? 'Reopen' : 'Mark ready' }}</button>
                                </div>
                            </template>
                            <form v-else class="mt-3 flex flex-col gap-3" @submit.prevent="saveGround(g)">
                                <div><label class="label" :for="`a${g.id}`">What our records show</label><textarea :id="`a${g.id}`" v-model="draft.answer" rows="4" class="field h-auto py-2"></textarea></div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><label class="label" :for="`u${g.id}`">Polling units cited</label><input :id="`u${g.id}`" v-model="draft.unit_codes" class="field" placeholder="PU001, PU002" /></div>
                                    <div><label class="label" :for="`e${g.id}`">Evidence in short</label><input :id="`e${g.id}`" v-model="draft.evidence_note" class="field" placeholder="46 EC8A photos · 46 IReV captures" /></div>
                                </div>
                                <div><label class="label" :for="`o${g.id}`">Owner</label><select :id="`o${g.id}`" v-model="draft.owner_id" class="field sm:w-72"><option value="">Nobody</option><option v-for="s in c.staff" :key="s.id" :value="s.id">{{ s.name }} · {{ s.role }}</option></select></div>
                                <div class="flex gap-2"><button class="btn-navy" :disabled="busy === `g${g.id}`">Save</button><button type="button" class="btn-outline" @click="editingGround = null">Cancel</button></div>
                            </form>
                        </li>
                    </ul>
                    <form v-if="ground.open" class="mt-3 flex flex-col gap-3 rounded-xl bg-mist p-4" @submit.prevent="addGround">
                        <div class="grid gap-3 sm:grid-cols-[200px_1fr]">
                            <div><label class="label" for="g-label">Ground</label><input id="g-label" v-model="ground.label" class="field" placeholder="OVER-VOTING" required /></div>
                            <div><label class="label" for="g-claim">What the petition claims</label><input id="g-claim" v-model="ground.claim" class="field" required /></div>
                        </div>
                        <div class="flex gap-2"><button class="btn-navy" :disabled="busy === 'g'">Add ground</button><button type="button" class="btn-outline" @click="ground.open = false">Cancel</button></div>
                    </form>
                    <button v-else class="btn-outline mt-3" @click="ground.open = true"><Icon name="plus" :size="16" /> Add a ground</button>
                </section>

                <div class="flex flex-col gap-5">
                    <section class="card p-5">
                        <h2 class="card-title ">Tasks</h2>
                        <ul class="mt-3">
                            <li v-for="t in c.tasks" :key="t.id" class="flex items-start gap-3 border-t border-[#eef0f3] py-2.5 first:border-0">
                                <input type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#17694b]" :checked="!!t.done_at" :aria-label="`Done: ${t.title}`" :disabled="busy === `t${t.id}`" @change="toggleTask(t)" />
                                <span class="min-w-0 flex-1"><span class="block text-sm font-semibold" :class="t.done_at ? 'text-ink-2 line-through' : ''">{{ t.title }}</span><span class="text-xs text-ink-2">{{ [t.owner?.name, t.due_on && `due ${fmtDate(t.due_on)}`].filter(Boolean).join(' · ') }}</span></span>
                            </li>
                        </ul>
                        <form class="mt-3 grid gap-2" @submit.prevent="addTask">
                            <input v-model="task.title" class="field h-9" placeholder="New task" aria-label="New task" required />
                            <div class="grid grid-cols-[1fr_140px_auto] gap-2">
                                <select v-model="task.owner_id" class="field h-9 text-[13px]" aria-label="Owner"><option value="">Owner</option><option v-for="s in c.staff" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                                <input v-model="task.due_on" type="date" class="field h-9 text-[13px]" aria-label="Due" />
                                <button class="btn-navy h-9" :disabled="busy === 't'">Add</button>
                            </div>
                        </form>
                    </section>
                    <section class="card p-5">
                        <h2 class="card-title ">Our evidence for the reply</h2>
                        <p class="mt-1 text-[13px] text-ink-2">Every unit cited goes into the bundle with its signed results, EC8A photos, IReV captures and the audit log, plus a SHA-256 manifest counsel can check.</p>
                        <p v-if="!evidenceUnits.length" class="mt-3 text-sm text-ink-3">No units cited yet.</p>
                        <ul class="mt-2">
                            <li v-for="u in evidenceUnits" :key="u.id" class="flex justify-between gap-2 border-t border-[#eef0f3] py-2 text-sm first:border-0">
                                <RouterLink :to="`/units/${u.id}/evidence`" class="font-bold text-navy hover:underline">{{ u.code }}</RouterLink>
                                <span class="truncate text-ink-2">{{ u.place }} · {{ u.ground }}</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </template>
    </LoadState>
</template>

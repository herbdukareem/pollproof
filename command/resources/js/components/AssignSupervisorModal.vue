<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Modal from './Modal.vue';
import PersonPicker from './PersonPicker.vue';
import SearchSelect from './SearchSelect.vue';
import { api } from '../lib/api';

/**
 * Put someone in a supervisor post: replace the holder, fill an empty post, or add a supervisor.
 * `post` fixes the post ({ level, lga, ward, backup }); without it the post is chosen here.
 * `person` presets who (e.g. "Make supervisor" from an agent's menu). `holder` is who holds it now.
 */
const props = defineProps({
    open: Boolean,
    post: { type: Object, default: null },
    holder: { type: Object, default: null },
    person: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lgas = ref([]);
const choice = reactive({ level: 'ward', lgaId: '', wardId: '', backup: false });
const chosen = ref(null);
const mode = ref('existing');
const fresh = reactive({ name: '', phone: '' });
const previous = ref('unassigned');
const busy = ref(false);
const error = ref('');

watch(() => props.open, async (o) => {
    if (!o) return;
    chosen.value = props.person ?? null;
    mode.value = 'existing';
    Object.assign(fresh, { name: '', phone: '' });
    previous.value = 'unassigned';
    error.value = '';
    Object.assign(choice, { level: 'ward', lgaId: '', wardId: '', backup: false });
    if (!props.post && !lgas.value.length) lgas.value = (await api.get('structure')).lgas ?? [];
});

const lgaOptions = computed(() => lgas.value.map((l) => ({ value: l.id, label: l.name })));
const wardOptions = computed(() => lgas.value.flatMap((l) => l.wards.map((w) => ({ value: w.id, label: `${w.name} · ${l.name}`, hint: `${w.units} units` }))));
const target = computed(() => {
    if (props.post) return props.post;
    if (choice.level === 'lga') {
        const l = lgas.value.find((x) => x.id === choice.lgaId);
        return l ? { level: 'lga', lga: l, ward: null, backup: choice.backup } : null;
    }
    const l = lgas.value.find((x) => x.wards.some((w) => w.id === choice.wardId));
    const w = l?.wards.find((x) => x.id === choice.wardId);
    return w ? { level: 'ward', lga: l, ward: w, backup: false } : null;
});
const postLabel = computed(() => {
    const p = target.value;
    if (!p) return '';
    return p.level === 'lga' ? `${p.backup ? 'Backup LGA supervisor' : 'LGA supervisor'} for ${p.lga.name}` : `Ward supervisor for ${p.ward.name}, ${p.lga?.name ?? ''}`.replace(/, $/, '');
});
const title = computed(() => (props.post ? (props.holder ? `Replace ${props.holder.name}` : `Assign ${postLabel.value.toLowerCase()}`) : props.person ? `Make ${props.person.name} a supervisor` : 'Add a supervisor'));

/** What moving this person will change, said before they save. */
const effects = computed(() => {
    const p = mode.value === 'existing' ? chosen.value : null;
    if (!p) return [];
    const out = [];
    if (p.role === 'agent' && p.unit) out.push(`${p.code} leaves ${p.unit}. The unit has no agent until you send a reserve from the Agents page.`);
    else if (p.role === 'agent') out.push(`${p.code} stops being ${p.post.toLowerCase().startsWith('reserve') ? 'a reserve' : 'an agent'}.`);
    if (p.has_result) out.push('Their result for the unit stays on record.');
    if (p.role !== 'agent' && !p.post.endsWith('no post')) out.push(`They leave their current post: ${p.post}.`);
    if (p.role === 'agent') out.push('They get a supervisor code and sign in to the app again to see the supervisor screens.');
    return out;
});
const ready = computed(() => target.value && (mode.value === 'existing' ? !!chosen.value : fresh.name.trim() && fresh.phone.trim()));

async function save() {
    if (!ready.value) return;
    busy.value = true;
    error.value = '';
    const p = target.value;
    try {
        const res = await api.post('supervisors', {
            ...(mode.value === 'existing' ? { user_id: chosen.value.id } : { name: fresh.name, phone: fresh.phone }),
            level: p.level,
            ...(p.level === 'lga' ? { lga_id: p.lga.id, is_backup: !!p.backup } : { ward_id: p.ward.id }),
            previous: previous.value,
        });
        const parts = [`${res.name} (${res.code}) is now ${postLabel.value.replace(/^\w/, (c) => c.toLowerCase())}.`];
        if (res.replaced) parts.push(res.previous_to === 'reserve' ? `${res.replaced} is now a reserve agent.` : `${res.replaced} has no post now.`);
        if (res.freed_unit) parts.push(`${res.freed_unit} needs an agent.`);
        emit('done', parts.join(' '));
        emit('close');
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="title" :subtitle="post ? postLabel : 'Choose the post, then who fills it.'" width="max-w-xl" :busy="busy" @close="emit('close')">
        <form id="assign-sup" class="flex flex-col gap-5" @submit.prevent="save">
            <fieldset v-if="!post" class="flex flex-col gap-3">
                <legend class="mb-2 text-sm font-bold">Post</legend>
                <div class="inline-flex w-fit rounded-xl bg-mist p-1 text-[13px] font-semibold">
                    <button type="button" class="rounded-lg px-3 py-1.5" :class="choice.level === 'ward' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="choice.level = 'ward'">Ward supervisor</button>
                    <button type="button" class="rounded-lg px-3 py-1.5" :class="choice.level === 'lga' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="choice.level = 'lga'">LGA supervisor</button>
                </div>
                <SearchSelect v-if="choice.level === 'ward'" v-model="choice.wardId" :options="wardOptions" placeholder="Type a ward or LGA…" noun="wards" aria-label="Ward" />
                <template v-else>
                    <SearchSelect v-model="choice.lgaId" :options="lgaOptions" placeholder="Type an LGA…" noun="LGAs" aria-label="LGA" />
                    <label class="flex items-center gap-2 text-sm"><input v-model="choice.backup" type="checkbox" class="h-4 w-4 accent-navy" /> As the backup LGA supervisor</label>
                </template>
            </fieldset>

            <div>
                <div class="mb-2 text-sm font-bold">{{ person ? 'Person' : 'Who' }}</div>
                <div v-if="person" class="rounded-xl bg-mist p-3 text-sm"><b>{{ person.name }}</b> · {{ person.code }}<div class="text-[12.5px] text-ink-2">{{ person.post }}</div></div>
                <PersonPicker v-else v-model="chosen" v-model:mode="mode" :fresh="fresh" :exclude="holder?.id ?? null" />
                <ul v-if="effects.length" class="mt-2 list-disc space-y-0.5 rounded-xl bg-warn-bg/70 py-2 pr-3 pl-7 text-[12.5px] text-warn">
                    <li v-for="e in effects" :key="e">{{ e }}</li>
                </ul>
            </div>

            <fieldset v-if="holder || !post">
                <legend class="mb-2 text-sm font-bold">{{ holder ? `What happens to ${holder.name}?` : 'If someone holds this post now' }}</legend>
                <label class="flex items-start gap-2 rounded-xl border border-line p-3 text-sm" :class="previous === 'unassigned' ? 'border-navy bg-[#f4f6fb]' : ''">
                    <input v-model="previous" type="radio" value="unassigned" class="mt-0.5 h-4 w-4 accent-navy" />
                    <span><b>Keep them, without a post</b><span class="block text-[12.5px] text-ink-2">They stay registered as a supervisor and can be given another post later.</span></span>
                </label>
                <label class="mt-2 flex items-start gap-2 rounded-xl border border-line p-3 text-sm" :class="previous === 'reserve' ? 'border-navy bg-[#f4f6fb]' : ''">
                    <input v-model="previous" type="radio" value="reserve" class="mt-0.5 h-4 w-4 accent-navy" />
                    <span><b>Make them a reserve agent</b><span class="block text-[12.5px] text-ink-2">They can be sent to a polling unit that needs an agent.</span></span>
                </label>
            </fieldset>

            <p v-if="error" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        </form>
        <template #footer>
            <button class="btn-outline" :disabled="busy" @click="emit('close')">Cancel</button>
            <button class="btn-primary" form="assign-sup" :disabled="!ready || busy">{{ busy ? 'Saving…' : holder ? 'Replace' : 'Save' }}</button>
        </template>
    </Modal>
</template>

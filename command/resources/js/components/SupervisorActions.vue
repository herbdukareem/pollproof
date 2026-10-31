<script setup>
import { computed, ref, watch } from 'vue';
import ActionMenu from './ActionMenu.vue';
import Modal from './Modal.vue';
import SearchSelect from './SearchSelect.vue';
import { api } from '../lib/api';

/**
 * ⋯ for a supervisor: replace them, make them an agent (at a unit or as a reserve), or take them off the post.
 * `wardId` is the ward to suggest when making them an agent. Emits `replace` and `done` (with a message).
 */
const props = defineProps({ supervisor: { type: Object, required: true }, wardId: { type: Number, default: null } });
const emit = defineEmits(['replace', 'done']);
const dialog = ref('');
const busy = ref(false);
const error = ref('');
const lgas = ref([]);
const ward = ref('');
const where = ref('unit');
const unit = ref('');
const units = ref([]);

const s = computed(() => props.supervisor);
const items = computed(() => [
    { label: 'Replace', icon: 'users', hint: 'Choose someone else for this post', run: () => emit('replace') },
    { label: 'Make agent', icon: 'move', hint: 'At a polling unit, or as a reserve', run: open },
    { label: 'Remove from post', icon: 'undo', danger: true, hint: 'Stays registered, without a post', run: () => { error.value = ''; dialog.value = 'remove'; } },
]);
const wardOptions = computed(() => lgas.value.flatMap((l) => l.wards.map((w) => ({ value: w.id, label: `${w.name} · ${l.name}` }))));
const unitOptions = computed(() => units.value.map((u) => ({ value: u.id, label: `${u.code} · ${u.name}`, hint: u.agent ? `Agent ${u.agent.code} becomes a reserve` : 'No agent now' })));

async function open() {
    error.value = '';
    where.value = 'unit';
    unit.value = '';
    ward.value = props.wardId ?? '';
    dialog.value = 'agent';
    if (!lgas.value.length) lgas.value = (await api.get('structure')).lgas ?? [];
    if (!ward.value) ward.value = lgas.value[0]?.wards[0]?.id ?? '';
}
watch(ward, async (w) => {
    unit.value = '';
    units.value = w ? (await api.get('units', { ward_id: w, per_page: 200, sort: 'code', dir: 'asc' })).data : [];
});

async function run(fn) {
    busy.value = true;
    error.value = '';
    try {
        emit('done', await fn());
        dialog.value = '';
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}
const makeAgent = () => run(async () => {
    const res = await api.post(`people/${s.value.id}/make-agent`, where.value === 'unit' ? { polling_unit_id: unit.value } : { ward_id: ward.value });
    return `${s.value.name} is now ${res.code}: ${res.post.toLowerCase()}.${res.replaced ? ` ${res.replaced.code} is now a reserve.` : ''}`;
});
const remove = () => run(async () => {
    await api.post(`people/${s.value.id}/unassign`);
    return `${s.value.name} has been taken off the post. The post is now open.`;
});
</script>

<template>
    <ActionMenu :items="items" :label="`Actions for ${supervisor.code}`" />

    <Modal :open="dialog === 'agent'" :title="`Make ${supervisor.name} an agent`" subtitle="Their post opens up. They get an agent code and sign in to the app again." :busy="busy" @close="dialog = ''">
        <div class="flex flex-col gap-4">
            <div class="inline-flex w-fit rounded-xl bg-mist p-1 text-[13px] font-semibold">
                <button type="button" class="rounded-lg px-3 py-1.5" :class="where === 'unit' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="where = 'unit'">At a polling unit</button>
                <button type="button" class="rounded-lg px-3 py-1.5" :class="where === 'reserve' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="where = 'reserve'">As a reserve</button>
            </div>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Ward <SearchSelect v-model="ward" :options="wardOptions" placeholder="Type a ward or LGA…" noun="wards" /></label>
            <label v-if="where === 'unit'" class="flex flex-col gap-1.5 text-sm font-semibold">Polling unit <SearchSelect v-model="unit" :options="unitOptions" placeholder="Type a unit code or name…" noun="units" /></label>
            <p v-else class="text-[13px] text-ink-2">They wait in this ward and can be sent to any unit that needs an agent.</p>
            <p v-if="error" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        </div>
        <template #footer>
            <button class="btn-outline" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-primary" :disabled="busy || !ward || (where === 'unit' && !unit)" @click="makeAgent">{{ busy ? 'Saving…' : 'Make agent' }}</button>
        </template>
    </Modal>

    <Modal :open="dialog === 'remove'" :title="`Take ${supervisor.name} off this post?`" subtitle="They stay registered as a supervisor and can be given another post later." :busy="busy" @close="dialog = ''">
        <p class="text-sm text-ink-2">The post will show as open until someone else is assigned.</p>
        <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        <template #footer>
            <button class="btn-outline" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-primary" :disabled="busy" @click="remove">{{ busy ? 'Removing…' : 'Remove from post' }}</button>
        </template>
    </Modal>
</template>

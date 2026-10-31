<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Modal from './Modal.vue';
import SearchSelect from './SearchSelect.vue';
import { api } from '../lib/api';

/** Add one agent: name, phone, an optional photo for their ID tag, and a unit or a reserve place. */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'done']);
const lgas = ref([]);
const form = reactive({ name: '', phone: '', where: 'unit', wardId: '', unitId: '' });
const units = ref([]);
const photo = ref(null);
const preview = ref('');
const busy = ref(false);
const error = ref('');

watch(() => props.open, async (o) => {
    if (!o) return;
    Object.assign(form, { name: '', phone: '', where: 'unit', wardId: '', unitId: '' });
    photo.value = null;
    preview.value = '';
    error.value = '';
    if (!lgas.value.length) lgas.value = (await api.get('structure')).lgas ?? [];
});
const wardOptions = computed(() => lgas.value.flatMap((l) => l.wards.map((w) => ({ value: w.id, label: `${w.name} · ${l.name}`, hint: `${w.units} units` }))));
const unitOptions = computed(() => units.value.map((u) => ({ value: u.id, label: `${u.code} · ${u.name}`, hint: u.agent ? `Has agent ${u.agent.code}: they become a reserve` : 'No agent yet' })));
const chosenUnit = computed(() => units.value.find((u) => u.id === form.unitId));
watch(() => form.wardId, async (w) => {
    form.unitId = '';
    units.value = w ? (await api.get('units', { ward_id: w, per_page: 200, sort: 'code', dir: 'asc' })).data : [];
});
function pick(e) {
    photo.value = e.target.files?.[0] ?? null;
    preview.value = photo.value ? URL.createObjectURL(photo.value) : '';
}
const ready = computed(() => form.name.trim() && form.phone.trim() && form.wardId && (form.where === 'reserve' || form.unitId));

async function save() {
    if (!ready.value) return;
    busy.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('name', form.name.trim());
    fd.append('phone', form.phone.trim());
    if (form.where === 'unit') fd.append('polling_unit_id', form.unitId);
    else fd.append('ward_id', form.wardId);
    if (photo.value) fd.append('photo', photo.value);
    try {
        const res = await api.post('roster/agents', fd);
        emit('done', `${res.name} added as ${res.code}: ${res.post.toLowerCase()}.${res.replaced ? ` ${res.replaced.code} is now a reserve.` : ''}`, res);
        emit('close');
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" title="Add an agent" subtitle="They sign in to the app with this phone number." width="max-w-xl" :busy="busy" @close="emit('close')">
        <form id="add-agent" class="flex flex-col gap-4" @submit.prevent="save">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-1.5 text-sm font-semibold">Full name <input v-model="form.name" class="field" required maxlength="120" autofocus /></label>
                <label class="flex flex-col gap-1.5 text-sm font-semibold">Phone <input v-model="form.phone" class="field" required inputmode="tel" placeholder="0803 123 4567" /></label>
            </div>
            <div class="flex items-center gap-3">
                <img v-if="preview" :src="preview" alt="" class="h-20 w-16 rounded-lg border border-line object-cover" />
                <div v-else class="flex h-20 w-16 items-center justify-center rounded-lg bg-mist text-center text-[11px] text-ink-3">No photo</div>
                <label class="flex flex-col gap-1 text-sm font-semibold">
                    Photo for their ID tag <span class="font-normal text-ink-3">(optional)</span>
                    <input type="file" accept="image/*" capture="user" class="text-xs font-normal" @change="pick" />
                    <span class="text-xs font-normal text-ink-2">A clear face, like a passport photo. You can add it later.</span>
                </label>
            </div>
            <div>
                <div class="mb-2 inline-flex rounded-xl bg-mist p-1 text-[13px] font-semibold">
                    <button type="button" class="rounded-lg px-3 py-1.5" :class="form.where === 'unit' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="form.where = 'unit'">At a polling unit</button>
                    <button type="button" class="rounded-lg px-3 py-1.5" :class="form.where === 'reserve' ? 'bg-white shadow-sm' : 'text-ink-2'" @click="form.where = 'reserve'">As a reserve</button>
                </div>
                <div class="grid gap-3">
                    <label class="flex flex-col gap-1.5 text-sm font-semibold">Ward <SearchSelect v-model="form.wardId" :options="wardOptions" placeholder="Type a ward or LGA…" noun="wards" /></label>
                    <label v-if="form.where === 'unit'" class="flex flex-col gap-1.5 text-sm font-semibold">Polling unit <SearchSelect v-model="form.unitId" :options="unitOptions" :placeholder="form.wardId ? 'Type a unit code or name…' : 'Choose the ward first'" :disabled="!form.wardId" noun="units" /></label>
                    <p v-if="form.where === 'reserve'" class="text-[13px] text-ink-2">Reserves wait in their ward and can be sent to any unit that needs an agent.</p>
                    <p v-if="chosenUnit?.agent" class="rounded-xl bg-warn-bg/70 px-3 py-2 text-[12.5px] text-warn">{{ chosenUnit.code }} already has {{ chosenUnit.agent.name }} ({{ chosenUnit.agent.code }}). They become a reserve in the ward.</p>
                </div>
            </div>
            <p v-if="error" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        </form>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="emit('close')">Cancel</button>
            <button class="btn-primary" form="add-agent" :disabled="!ready || busy">{{ busy ? 'Adding…' : 'Add agent' }}</button>
        </template>
    </Modal>
</template>

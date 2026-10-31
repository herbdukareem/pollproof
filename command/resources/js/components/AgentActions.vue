<script setup>
import { computed, reactive, ref, watch } from 'vue';
import ActionMenu from './ActionMenu.vue';
import Modal from './Modal.vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';
import AssignSupervisorModal from './AssignSupervisorModal.vue';

/**
 * Row actions for a polling-unit agent: edit details, move to a unit, release to the reserves,
 * deactivate or reactivate. Emits `done` with a message after a change is saved.
 * canEdit: situation room and DG. canAssign: anyone who can send reserves (supervisors too).
 */
const props = defineProps({ agent: { type: Object, required: true }, canEdit: Boolean, canAssign: Boolean });
const emit = defineEmits(['done']);

const dialog = ref('');
const busy = ref(false);
const error = ref('');
const form = reactive({ name: '', phone: '' });
const unitSearch = ref('');
const units = ref([]);
const searching = ref(false);
const target = ref(null);
const emptyUnits = ref(false);

const a = computed(() => props.agent);
// The agent as the supervisor window describes people.
const asPerson = computed(() => ({
    id: a.value.id, code: a.value.code, name: a.value.name, phone: a.value.phone, role: 'agent',
    unit: a.value.polling_unit?.code ?? null, has_result: !!a.value.result,
    post: a.value.polling_unit ? `Agent at ${a.value.polling_unit.code}` : 'Reserve agent',
}));
const items = computed(() => {
    const list = [];
    if (props.canEdit) list.push({ label: 'Edit details', icon: 'edit', hint: 'Name and phone number', run: () => openDialog('edit') });
    if (props.canAssign && a.value.active !== false) list.push({ label: a.value.polling_unit ? 'Move to another unit' : 'Assign to a unit', icon: 'move', run: () => openDialog('move') });
    if (props.canEdit && a.value.polling_unit && a.value.active !== false) list.push({ label: 'Release to reserves', icon: 'undo', hint: `Frees ${a.value.polling_unit.code}`, run: () => openDialog('release') });
    if (props.canEdit && a.value.active !== false) list.push({ label: 'Make supervisor', icon: 'users', hint: 'Ward or LGA supervisor', run: () => (dialog.value = 'supervisor') });
    if (props.canEdit) {
        list.push(a.value.active === false
            ? { label: 'Reactivate', icon: 'power', hint: 'Comes back as a reserve', run: () => openDialog('reactivate') }
            : { label: 'Deactivate', icon: 'power', danger: true, hint: 'Signs them out of the app', run: () => openDialog('deactivate') });
    }
    return list;
});

function openDialog(kind) {
    error.value = '';
    form.name = a.value.name;
    form.phone = a.value.phone ?? '';
    unitSearch.value = '';
    units.value = [];
    target.value = null;
    dialog.value = kind;
    if (kind === 'move') findUnits();
}

let t;
watch(unitSearch, () => { clearTimeout(t); t = setTimeout(findUnits, 250); });
async function findUnits() {
    searching.value = true;
    try {
        let res = await api.get('units', { search: unitSearch.value, per_page: 10, ...(unitSearch.value ? {} : { status: 'no_agent' }) });
        emptyUnits.value = !unitSearch.value && !res.data.length;
        // Every unit is covered: show units anyway, so a swap is still possible.
        if (emptyUnits.value) res = await api.get('units', { per_page: 10 });
        units.value = res.data;
    } catch {
        units.value = [];
    } finally {
        searching.value = false;
    }
}

async function save(fn, message) {
    busy.value = true;
    error.value = '';
    try {
        await fn();
        dialog.value = '';
        emit('done', message);
    } catch (e) {
        error.value = e.firstError?.() ?? e.message;
    } finally {
        busy.value = false;
    }
}

const saveEdit = () => {
    const body = {};
    if (form.name.trim() !== a.value.name) body.name = form.name.trim();
    if (a.value.phone != null && form.phone.trim() !== a.value.phone) body.phone = form.phone.trim();
    if (!Object.keys(body).length) return (dialog.value = '');
    save(() => api.patch(`agents/${a.value.id}`, body), `Saved ${a.value.code}.`);
};
const saveMove = () => save(() => api.post(`agents/${a.value.id}/assign`, { polling_unit_id: target.value.id }), `${a.value.code} is now the agent for ${target.value.code}.`);
const saveRelease = () => save(() => api.patch(`agents/${a.value.id}`, { release: true }), `${a.value.code} released to the reserves; ${a.value.polling_unit.code} has no agent now.`);
const saveActive = (active) => save(() => api.patch(`agents/${a.value.id}`, { active }), active ? `${a.value.code} reactivated as a reserve.` : `${a.value.code} deactivated and signed out.`);
</script>

<template>
    <ActionMenu v-if="items.length" :items="items" :label="`Actions for ${agent.code}`" />
    <AssignSupervisorModal :open="dialog === 'supervisor'" :person="asPerson" @close="dialog = ''" @done="(m) => emit('done', m)" />

    <Modal :open="dialog === 'edit'" :title="`Edit ${agent.code}`" subtitle="Corrections are recorded in the audit log." :busy="busy" @close="dialog = ''">
        <form id="agent-edit" class="flex flex-col gap-4" @submit.prevent="saveEdit">
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Full name <input v-model="form.name" class="field" required maxlength="120" /></label>
            <label v-if="agent.phone != null" class="flex flex-col gap-1.5 text-sm font-semibold">
                Phone number <input v-model="form.phone" class="field" required inputmode="tel" />
                <span class="text-xs font-normal text-ink-2">Changing the number signs them out; they sign in again with the new number.</span>
            </label>
            <p v-if="error" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        </form>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-navy" form="agent-edit" :disabled="busy">{{ busy ? 'Saving…' : 'Save changes' }}</button>
        </template>
    </Modal>

    <Modal :open="dialog === 'move'" :title="agent.polling_unit ? `Move ${agent.code}` : `Assign ${agent.code}`" :subtitle="agent.polling_unit ? `Now at ${agent.polling_unit.code}. Pick the unit they should cover instead.` : 'Pick the polling unit they should cover.'" :busy="busy" width="max-w-xl" @close="dialog = ''">
        <div class="relative">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <input v-model="unitSearch" class="field w-full pl-9" placeholder="Unit code or name, e.g. 06-03 or Kpakungu" autofocus />
        </div>
        <p class="mt-2 text-xs text-ink-2">{{ unitSearch ? 'Matching units' : emptyUnits ? 'Every unit has an agent. Search for the unit to swap into.' : 'Units without an agent' }}{{ searching ? ' · searching…' : '' }}</p>
        <ul class="mt-2 flex max-h-[320px] flex-col gap-1.5 overflow-y-auto">
            <li v-for="u in units" :key="u.id">
                <button class="flex w-full items-center gap-3 rounded-xl border-2 px-3 py-2.5 text-left text-sm transition" :class="target?.id === u.id ? 'border-navy bg-white shadow-sm' : 'border-transparent bg-mist hover:bg-[#eceff3]'" :disabled="u.id === agent.polling_unit?.id" @click="target = u">
                    <span class="min-w-0 flex-1"><b>{{ u.code }}</b> <span class="text-ink-2">{{ u.name }}</span><span class="block text-xs text-ink-2">{{ u.ward }} · {{ u.lga }}</span></span>
                    <span class="shrink-0 text-xs font-semibold" :class="u.agent ? 'text-[#b4470a]' : 'text-good'">{{ u.id === agent.polling_unit?.id ? 'Current unit' : u.agent ? `Has ${u.agent.code}` : 'No agent' }}</span>
                </button>
            </li>
            <li v-if="!searching && !units.length" class="py-6 text-center text-sm text-ink-2">No units match.</li>
        </ul>
        <p v-if="target?.agent" class="mt-3 rounded-lg bg-warn-bg px-3 py-2 text-[13px] text-warn">{{ target.agent.code }} covers {{ target.code }} now. They will lose access to it and go back to the reserves.</p>
        <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-navy" :disabled="busy || !target" @click="saveMove">{{ busy ? 'Saving…' : target ? `Assign to ${target.code}` : 'Choose a unit' }}</button>
        </template>
    </Modal>

    <Modal :open="dialog === 'release'" :title="`Release ${agent.code} to the reserves?`" :busy="busy" @close="dialog = ''">
        <p class="text-sm text-ink-2">{{ agent.name }} stops covering <b class="text-ink">{{ agent.polling_unit?.code }}</b> and joins the reserves. The unit shows as having no agent until someone is sent there.</p>
        <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-navy" :disabled="busy" @click="saveRelease">{{ busy ? 'Saving…' : 'Release' }}</button>
        </template>
    </Modal>

    <Modal :open="dialog === 'deactivate'" :title="`Deactivate ${agent.code}?`" :busy="busy" @close="dialog = ''">
        <ul class="flex list-disc flex-col gap-1.5 pl-5 text-sm text-ink-2">
            <li>{{ agent.name }} is signed out of the app at once and can't sign in again.</li>
            <li v-if="agent.polling_unit"><b class="text-ink">{{ agent.polling_unit.code }}</b> is freed so a reserve can be sent.</li>
            <li>Results and check-ins they already sent are kept.</li>
        </ul>
        <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-primary" :disabled="busy" @click="saveActive(false)">{{ busy ? 'Deactivating…' : 'Deactivate' }}</button>
        </template>
    </Modal>

    <Modal :open="dialog === 'reactivate'" :title="`Reactivate ${agent.code}?`" :busy="busy" @close="dialog = ''">
        <p class="text-sm text-ink-2">{{ agent.name }} can sign in again and joins the reserves. Assign them to a unit afterwards if needed.</p>
        <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="dialog = ''">Cancel</button>
            <button class="btn-navy" :disabled="busy" @click="saveActive(true)">{{ busy ? 'Saving…' : 'Reactivate' }}</button>
        </template>
    </Modal>
</template>

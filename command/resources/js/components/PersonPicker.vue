<script setup>
import { ref, watch } from 'vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';

/**
 * Choose who fills a post: someone already registered (an agent, reserve or supervisor, found by
 * name, code or phone) or a new person. v-model is the chosen person, v-model:fresh the new
 * person's { name, phone }, v-model:mode 'existing' or 'new'.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    fresh: { type: Object, required: true },
    mode: { type: String, default: 'existing' },
    exclude: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue', 'update:mode']);
const search = ref('');
const results = ref([]);
const loading = ref(false);
const tone = { agent: 'bg-[#eef5fc] text-[#1c5a94]', ward_supervisor: 'bg-good-bg text-good', lga_supervisor: 'bg-navy text-white' };

let t;
watch(search, () => { clearTimeout(t); t = setTimeout(find, 250); });
watch(() => props.mode, (m) => m === 'existing' && !results.value.length && find(), { immediate: true });

async function find() {
    loading.value = true;
    try {
        results.value = (await api.get('setup/people', { search: search.value })).data.filter((p) => p.id !== props.exclude);
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-3 inline-flex rounded-xl bg-mist p-1 text-[13px] font-semibold" role="tablist">
            <button type="button" role="tab" class="rounded-lg px-3 py-1.5" :class="mode === 'existing' ? 'bg-white shadow-sm' : 'text-ink-2'" :aria-selected="mode === 'existing'" @click="emit('update:mode', 'existing')">Someone already registered</button>
            <button type="button" role="tab" class="rounded-lg px-3 py-1.5" :class="mode === 'new' ? 'bg-white shadow-sm' : 'text-ink-2'" :aria-selected="mode === 'new'" @click="emit('update:mode', 'new')">New person</button>
        </div>

        <template v-if="mode === 'existing'">
            <div v-if="modelValue" class="flex items-center gap-3 rounded-xl border-2 border-navy bg-[#f4f6fb] p-3">
                <div class="min-w-0 flex-1 text-sm">
                    <b>{{ modelValue.name }}</b> <span class="text-ink-2">· {{ modelValue.code }}</span>
                    <div class="text-[12.5px] text-ink-2">{{ modelValue.post }}<template v-if="modelValue.phone"> · {{ modelValue.phone }}</template></div>
                </div>
                <button type="button" class="btn-outline h-8 px-3 text-xs" @click="emit('update:modelValue', null)">Change</button>
            </div>
            <template v-else>
                <div class="relative">
                    <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                    <input v-model="search" class="field pl-9" placeholder="Name, code (AG-0012, WS-005) or phone" aria-label="Find a person" autofocus />
                </div>
                <ul class="mt-2 max-h-64 overflow-y-auto rounded-xl border border-line" :class="loading ? 'opacity-60' : ''">
                    <li v-if="!results.length" class="px-3 py-4 text-center text-[13px] text-ink-2">{{ loading ? 'Searching…' : search ? `Nobody matches “${search}”. Try the phone number, or add a new person.` : 'No agents or supervisors yet.' }}</li>
                    <li v-for="p in results" :key="p.id" class="border-b border-[#eef0f3] last:border-0">
                        <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-mist disabled:cursor-not-allowed disabled:opacity-50" :disabled="!p.active" @click="emit('update:modelValue', p)">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">{{ p.name }} <span class="font-normal text-ink-2">· {{ p.code }}</span></span>
                                <span class="block truncate text-[12px] text-ink-2">{{ p.post }}<template v-if="p.phone"> · {{ p.phone }}</template></span>
                            </span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold tracking-wide uppercase" :class="tone[p.role]">{{ p.role === 'agent' ? 'Agent' : p.role === 'ward_supervisor' ? 'Ward sup.' : 'LGA sup.' }}</span>
                        </button>
                    </li>
                </ul>
            </template>
        </template>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Full name <input v-model="fresh.name" class="field" required maxlength="120" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Phone <input v-model="fresh.phone" class="field" required inputmode="tel" placeholder="0803 123 4567" /></label>
            <p class="text-[12.5px] text-ink-2 sm:col-span-2">If this phone already belongs to an agent or supervisor, they are moved into this post instead.</p>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import Icon from './Icon.vue';
import { num } from '../lib/format';

/**
 * Footer for any long list: "Showing 26–50 of 822", rows per page, and numbered pages.
 * `meta` is a Laravel paginator (current_page, last_page, per_page, total, from, to)
 * or the same shape from usePaged() for lists loaded in one go.
 */
const props = defineProps({
    meta: { type: Object, default: null },
    noun: { type: String, default: 'rows' },
    perPageOptions: { type: Array, default: () => [25, 50, 100] },
    loading: Boolean,
    /** Sort state from useSortState(): shows "Sorted by … · Latest first" when not the default. */
    sort: { type: Object, default: null },
});
const emit = defineEmits(['page', 'per-page']);
const root = ref(null);

const cur = computed(() => Number(props.meta?.current_page ?? props.meta?.page ?? 1));
const last = computed(() => Math.max(1, Number(props.meta?.last_page ?? 1)));
const total = computed(() => Number(props.meta?.total ?? 0));
const per = computed(() => Number(props.meta?.per_page ?? props.perPageOptions[0]));
const from = computed(() => props.meta?.from ?? (total.value ? (cur.value - 1) * per.value + 1 : 0));
const to = computed(() => props.meta?.to ?? Math.min(total.value, cur.value * per.value));

/** 1 … 4 5 6 … 33: the first, the last, and two either side of the current page. */
const pages = computed(() => {
    const out = [];
    for (let p = 1; p <= last.value; p++) {
        if (p === 1 || p === last.value || Math.abs(p - cur.value) <= 1 || (cur.value <= 3 && p <= 4) || (cur.value >= last.value - 2 && p >= last.value - 3)) out.push(p);
        else if (out[out.length - 1] !== '…') out.push('…');
    }
    return out;
});

function go(p) {
    if (p < 1 || p > last.value || p === cur.value) return;
    emit('page', p);
    // Bring the top of the list back into view if it has scrolled off.
    const box = root.value?.closest('section, .card');
    if (box && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <div v-if="meta" ref="root" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-[#eef0f3] px-4 py-3 text-[13px] text-ink-2" :class="loading ? 'opacity-70' : ''">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span v-if="total">Showing <b class="text-ink">{{ num(from) }}–{{ num(to) }}</b> of <b class="text-ink">{{ num(total) }}</b> {{ noun }}</span>
            <span v-else>No {{ noun }}</span>
            <span v-if="sort && !sort.isDefault" class="inline-flex items-center gap-1.5">
                Sorted by <b class="text-ink">{{ sort.label }} {{ sort.dir === 'asc' ? '↑' : '↓' }}</b>
                <button class="rounded-full border border-[#d6dae1] bg-white px-2 py-0.5 text-xs font-semibold text-navy hover:border-navy" @click="sort.reset()">{{ sort.initial.key === 'latest' ? 'Latest first' : `Back to ${sort.columns[sort.initial.key]?.label ?? 'default'}` }}</button>
            </span>
            <label v-if="perPageOptions.length > 1 && total > perPageOptions[0]" class="flex items-center gap-2">
                <span>Per page</span>
                <select class="h-8 rounded-lg border border-[#d6dae1] bg-white px-2 text-[13px] font-semibold text-ink" :value="per" @change="emit('per-page', Number($event.target.value))">
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </label>
        </div>
        <nav v-if="last > 1" class="flex items-center gap-1" aria-label="Pages">
            <button class="pager-btn" :disabled="cur <= 1" aria-label="Previous page" @click="go(cur - 1)"><Icon name="chevron" :size="16" class="rotate-180" /></button>
            <template v-for="(p, i) in pages" :key="i">
                <span v-if="p === '…'" class="hidden w-6 text-center sm:inline">…</span>
                <button
                    v-else
                    class="pager-btn"
                    :class="[p === cur ? 'pager-on' : 'hidden sm:inline-flex']"
                    :aria-current="p === cur ? 'page' : null"
                    :aria-label="`Page ${p}`"
                    @click="go(p)"
                >{{ p }}</button>
            </template>
            <span class="px-1 sm:hidden">of {{ last }}</span>
            <button class="pager-btn" :disabled="cur >= last" aria-label="Next page" @click="go(cur + 1)"><Icon name="chevron" :size="16" /></button>
        </nav>
    </div>
</template>

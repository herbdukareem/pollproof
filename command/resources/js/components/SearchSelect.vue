<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import Icon from './Icon.vue';

/**
 * A select you can type into to filter, for long lists (collation centres, LGAs, reserves).
 * Works like v-model on a <select>: options are [{ value, label, hint? }]. Type any words
 * in any order ("kpaki shiroro"); ↑ ↓ to move, Enter to choose, Esc to close.
 * `allLabel` adds a first option such as "All LGAs" whose value is `allValue` ('' by default).
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Choose…' },
    id: { type: String, default: null },
    required: Boolean,
    disabled: Boolean,
    allLabel: { type: String, default: null },
    allValue: { type: [String, Number], default: '' },
    size: { type: String, default: 'md' }, // md (h-11), sm (h-9), xs (h-8)
    noun: { type: String, default: 'options' },
    ariaLabel: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const query = ref('');
const active = ref(0);
const box = ref(null);
const input = ref(null);
const list = ref(null);
const pos = ref({});
const uid = `ss-${Math.random().toString(36).slice(2, 8)}`;

const same = (a, b) => String(a ?? '') === String(b ?? '');
const all = computed(() => (props.allLabel ? [{ value: props.allValue, label: props.allLabel, all: true }, ...props.options] : props.options));
const selected = computed(() => all.value.find((o) => same(o.value, props.modelValue)) ?? null);
const words = computed(() => query.value.toLowerCase().split(/\s+/).filter(Boolean));
const shown = computed(() => (words.value.length
    ? all.value.filter((o) => !o.all && words.value.every((w) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(w)))
    : all.value));
const height = { md: 'h-11 text-sm', sm: 'h-9 text-sm', xs: 'h-8 text-xs' }[props.size] ?? 'h-11 text-sm';

/** The matching part of a label in bold, so it is clear why an option is listed. */
function parts(label) {
    const w = words.value[0];
    const i = w ? label.toLowerCase().indexOf(w) : -1;
    return i < 0 ? [[label, false]] : [[label.slice(0, i), false], [label.slice(i, i + w.length), true], [label.slice(i + w.length), false]];
}

function place() {
    const r = input.value?.getBoundingClientRect();
    if (!r) return;
    const width = Math.max(r.width, 240);
    const left = Math.min(r.left, window.innerWidth - width - 8);
    const below = window.innerHeight - r.bottom;
    pos.value = below < 300 && r.top > below
        ? { left: `${left}px`, width: `${width}px`, bottom: `${window.innerHeight - r.top + 4}px`, maxHeight: `${Math.min(320, r.top - 16)}px` }
        : { left: `${left}px`, width: `${width}px`, top: `${r.bottom + 4}px`, maxHeight: `${Math.min(320, below - 16)}px` };
}

function onOutside(e) {
    if (!box.value?.contains(e.target) && !list.value?.contains(e.target)) close();
}
function onMove(e) {
    if (!list.value?.contains(e.target)) place();
}

function show() {
    if (open.value || props.disabled) return;
    open.value = true;
    query.value = '';
    active.value = Math.max(0, shown.value.findIndex((o) => same(o.value, props.modelValue)));
    place();
    document.addEventListener('mousedown', onOutside);
    window.addEventListener('scroll', onMove, true);
    window.addEventListener('resize', place);
    nextTick(scrollActive);
}
function close() {
    open.value = false;
    query.value = '';
    document.removeEventListener('mousedown', onOutside);
    window.removeEventListener('scroll', onMove, true);
    window.removeEventListener('resize', place);
}
onBeforeUnmount(close);

function choose(o) {
    emit('update:modelValue', o.value);
    close();
    input.value?.blur();
}
function scrollActive() {
    list.value?.querySelector(`[data-i="${active.value}"]`)?.scrollIntoView({ block: 'nearest' });
}
function key(e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open.value) return show();
        const n = shown.value.length;
        if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
        nextTick(scrollActive);
    } else if (e.key === 'Enter') {
        if (!open.value) return;
        e.preventDefault();
        if (shown.value[active.value]) choose(shown.value[active.value]);
    } else if (e.key === 'Escape') {
        if (open.value) e.stopPropagation();
        close();
    } else if (e.key === 'Tab') {
        close();
    }
}
function typed(e) {
    if (!open.value) show();
    query.value = e.target.value;
    active.value = 0;
    place();
}
</script>

<template>
    <div ref="box" class="relative">
        <input
            :id="id"
            ref="input"
            type="text"
            role="combobox"
            :aria-label="ariaLabel"
            autocomplete="off"
            :aria-expanded="open"
            :aria-controls="`${uid}-list`"
            :aria-activedescendant="open && shown[active] ? `${uid}-${active}` : null"
            class="field w-full cursor-pointer pr-9 focus:cursor-text"
            :class="[height, open || !selected ? '' : 'text-ink', disabled ? 'cursor-not-allowed opacity-60' : '']"
            :value="open ? query : (selected?.label ?? '')"
            :placeholder="open ? (selected?.label ?? 'Type to search…') : placeholder"
            :disabled="disabled"
            @focus="show"
            @click="show"
            @input="typed"
            @keydown="key"
        />
        <Icon v-if="!open" name="chevron" :size="14" class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 rotate-90 text-ink-2" />
        <Icon v-else name="search" :size="14" class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-ink-2" />
        <!-- Lets the browser's own "please choose" check work in forms. -->
        <input v-if="required" class="pointer-events-none absolute inset-x-0 bottom-0 h-px opacity-0" tabindex="-1" aria-hidden="true" :value="modelValue ?? ''" required @focus="input?.focus()" />
        <Teleport to="body">
            <Transition name="pop">
                <ul
                    v-if="open"
                    :id="`${uid}-list`"
                    ref="list"
                    role="listbox"
                    class="fixed z-[80] overflow-y-auto overscroll-contain rounded-xl border border-line bg-white py-1 text-sm shadow-xl"
                    :style="pos"
                >
                    <li v-if="!shown.length" class="px-3 py-2.5 text-ink-2">No {{ noun }} match “{{ query }}”.</li>
                    <li
                        v-for="(o, i) in shown"
                        :id="`${uid}-${i}`"
                        :key="String(o.value)"
                        :data-i="i"
                        role="option"
                        :aria-selected="same(o.value, modelValue)"
                        class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2"
                        :class="[i === active ? 'bg-mist' : '', o.all ? 'border-b border-[#eef0f3] font-semibold text-ink-2' : '']"
                        @mouseenter="active = i"
                        @mousedown.prevent="choose(o)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate"><template v-for="([t, hit], k) in parts(o.label)" :key="k"><b v-if="hit" class="text-navy">{{ t }}</b><template v-else>{{ t }}</template></template></span>
                            <span v-if="o.hint" class="block truncate text-xs text-ink-3">{{ o.hint }}</span>
                        </span>
                        <Icon v-if="same(o.value, modelValue)" name="check" :size="14" class="shrink-0 text-navy" />
                    </li>
                    <li v-if="words.length && shown.length" class="border-t border-[#eef0f3] px-3 pt-1.5 pb-1 text-[11.5px] text-ink-3">{{ shown.length }} of {{ options.length }} {{ noun }}</li>
                </ul>
            </Transition>
        </Teleport>
    </div>
</template>

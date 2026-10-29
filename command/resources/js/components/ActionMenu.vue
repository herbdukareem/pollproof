<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import Icon from './Icon.vue';

/**
 * The ⋯ button at the end of a table row. The menu is drawn over the page (not inside the table),
 * so a scrolling table never clips it. items: [{ label, icon, danger, disabled, hint, run }]
 */
const props = defineProps({ items: { type: Array, default: () => [] }, label: { type: String, default: 'Actions' } });
const open = ref(false);
const btn = ref(null);
const pos = ref({ top: 0, left: 0 });
const menu = ref(null);

async function toggle() {
    if (open.value) return close();
    open.value = true;
    await nextTick();
    place();
    // Listen only while open, and from the next frame, so the click that opened it doesn't close it.
    requestAnimationFrame(() => {
        window.addEventListener('resize', close);
        document.addEventListener('scroll', onScroll, true);
    });
}
function place() {
    const r = btn.value.getBoundingClientRect();
    const below = window.innerHeight - r.bottom > 52 * props.items.length + 24;
    pos.value = { top: below ? r.bottom + 6 : r.top - 6, left: Math.max(8, Math.min(window.innerWidth - 240, r.right - 232)), up: !below };
}
function onScroll(e) {
    if (menu.value?.contains(e.target)) return;
    close();
}
function close() {
    open.value = false;
    window.removeEventListener('resize', close);
    document.removeEventListener('scroll', onScroll, true);
}
function pick(item) {
    if (item.disabled) return;
    close();
    item.run?.();
}
onBeforeUnmount(close);
</script>

<template>
    <button ref="btn" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-ink-2 transition hover:border-[#d6dae1] hover:bg-white hover:text-ink" :class="open ? 'border-[#d6dae1] bg-white text-ink' : ''" :aria-label="label" aria-haspopup="menu" :aria-expanded="open" @click.stop="toggle">
        <Icon name="more" :size="20" />
    </button>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[55]" @click="close"></div>
        <Transition name="pop">
            <div
                v-if="open"
                ref="menu"
                role="menu"
                class="fixed z-[56] w-[232px] overflow-hidden rounded-xl border border-line bg-white py-1 text-left shadow-xl"
                :style="{ top: pos.top + 'px', left: pos.left + 'px', transform: pos.up ? 'translateY(-100%)' : null }"
            >
                <button
                    v-for="item in items"
                    :key="item.label"
                    role="menuitem"
                    class="flex w-full items-start gap-2.5 px-3.5 py-2.5 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-45"
                    :class="item.danger ? 'text-action hover:bg-bad-bg' : 'text-ink hover:bg-mist'"
                    :disabled="item.disabled"
                    :title="item.disabled && item.hint ? item.hint : null"
                    @click="pick(item)"
                >
                    <Icon :name="item.icon" :size="16" class="mt-0.5 shrink-0 opacity-80" />
                    <span><span class="block font-semibold">{{ item.label }}</span><span v-if="item.hint" class="block text-[12px] font-normal text-ink-2">{{ item.hint }}</span></span>
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

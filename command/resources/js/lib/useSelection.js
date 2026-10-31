import { computed, ref } from 'vue';

/**
 * Ticked rows for bulk actions. Keeps the ticks when the page, sort or filter changes,
 * so people can be picked across pages. The header box ticks or clears the rows on show.
 */
export function useSelection() {
    const ids = ref(new Set());
    const set = (next) => (ids.value = next);
    return {
        ids,
        count: computed(() => ids.value.size),
        list: () => [...ids.value],
        has: (id) => ids.value.has(id),
        toggle(id) {
            const next = new Set(ids.value);
            next.has(id) ? next.delete(id) : next.add(id);
            set(next);
        },
        setMany(list, on) {
            const next = new Set(ids.value);
            list.forEach((id) => (on ? next.add(id) : next.delete(id)));
            set(next);
        },
        allOn: (list) => list.length > 0 && list.every((id) => ids.value.has(id)),
        someOn: (list) => list.some((id) => ids.value.has(id)) && !list.every((id) => ids.value.has(id)),
        clear: () => set(new Set()),
    };
}

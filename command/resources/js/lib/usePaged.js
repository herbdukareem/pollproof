import { computed, ref, watch } from 'vue';

/**
 * Pages a list that was loaded in one go, giving the same meta shape as a Laravel paginator
 * so <Pagination> works the same for both. `resetOn` returns the filters; changing them goes back to page 1.
 */
export function usePaged(source, { perPage = 25, resetOn = null } = {}) {
    const page = ref(1);
    const size = ref(perPage);
    const list = computed(() => (typeof source === 'function' ? source() : source.value) ?? []);
    const last = computed(() => Math.max(1, Math.ceil(list.value.length / size.value)));

    // A refresh that shortens the list never leaves us on an empty page.
    watch(last, (l) => { if (page.value > l) page.value = l; });
    if (resetOn) watch(resetOn, () => (page.value = 1), { deep: true });

    const rows = computed(() => list.value.slice((page.value - 1) * size.value, page.value * size.value));
    const meta = computed(() => ({
        current_page: page.value,
        last_page: last.value,
        per_page: size.value,
        total: list.value.length,
        from: list.value.length ? (page.value - 1) * size.value + 1 : 0,
        to: Math.min(list.value.length, page.value * size.value),
    }));

    return {
        rows,
        meta,
        page,
        setPage: (p) => (page.value = p),
        setPerPage: (n) => { size.value = n; page.value = 1; },
    };
}

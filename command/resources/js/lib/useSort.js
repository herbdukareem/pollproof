import { computed, reactive } from 'vue';

/**
 * Click-to-sort for table headers. `columns` maps a key to { label, dir, value(row) }:
 * `dir` is the first direction on click (text 'asc', times and amounts 'desc'), `value` is what
 * a list loaded in one go is sorted by. The default sort is latest first.
 *
 * Clicking a header sorts by it; again flips the direction; a third time goes back to the default.
 * Rows without a value always go last. Equal rows keep their order.
 */
export function useSortState(columns, initial = { key: 'latest', dir: 'desc' }) {
    const state = reactive({
        key: initial.key,
        dir: initial.dir,
        columns,
        initial,
        isDefault: computed(() => state.key === initial.key && state.dir === initial.dir),
        label: computed(() => columns[state.key]?.label ?? 'latest'),
        toggle(key) {
            const first = columns[key]?.dir ?? 'asc';
            if (state.key !== key) {
                state.key = key;
                state.dir = first;
            } else if (state.dir === first || key === initial.key) {
                state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.reset();
            }
        },
        reset() {
            state.key = initial.key;
            state.dir = initial.dir;
        },
        /** For lists sorted by the server: ?sort=…&dir=… */
        params: computed(() => ({ sort: state.key, dir: state.dir })),
    });
    return state;
}

const text = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

export function sortRows(rows, state) {
    const col = state.columns[state.key];
    if (!col?.value) return rows;
    const sign = state.dir === 'asc' ? 1 : -1;
    const keyed = rows.map((row, i) => [col.value(row), i, row]);
    keyed.sort(([a, ia], [b, ib]) => {
        const na = a == null || a === '';
        const nb = b == null || b === '';
        if (na || nb) return na && nb ? ia - ib : na ? 1 : -1;
        const c = typeof a === 'number' && typeof b === 'number' ? a - b : text.compare(String(a), String(b));
        return c ? c * sign : ia - ib;
    });
    return keyed.map(([, , row]) => row);
}

/** Sort state plus the sorted rows, for a list loaded in one go. */
export function useSort(source, columns, initial) {
    const state = useSortState(columns, initial);
    const sorted = computed(() => sortRows((typeof source === 'function' ? source() : source.value) ?? [], state));
    return { sort: state, sorted };
}

/** A time as a number, for sorting. */
export const ts = (v) => (v ? new Date(v).getTime() : null);

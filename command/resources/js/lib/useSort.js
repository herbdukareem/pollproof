import { computed, reactive, watch } from 'vue';

/**
 * Where tables sort: 'server' (the default) asks the API for ?sort=…&dir=… and reloads;
 * 'client' sorts the rows already loaded in the browser. Set VITE_SORT_MODE=client when building
 * to sort in the browser everywhere, or pass { mode } to one table. Tables paged by the server
 * (Units, Audit log, Roster) always sort on the server.
 */
export const SORT_MODE = import.meta.env.VITE_SORT_MODE === 'client' ? 'client' : 'server';

/**
 * Click-to-sort for table headers. `columns` maps a key to { label, dir, value(row) }:
 * `dir` is the first direction on click (text 'asc', times and amounts 'desc'), `value` is what
 * a list loaded in one go is sorted by. The default sort is latest first.
 *
 * Clicking a header sorts by it; again flips the direction; a third time goes back to the default.
 * Rows without a value always go last. Equal rows keep their order.
 */
export function useSortState(columns, initial = { key: 'latest', dir: 'desc' }, { mode = SORT_MODE, onChange = null } = {}) {
    const state = reactive({
        server: mode === 'server',
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
        /** Query parameters for the API: ?sort=…&dir=… when the server sorts, nothing otherwise. */
        params: computed(() => (state.server ? { sort: state.key, dir: state.dir } : {})),
    });
    // When the server sorts, a header click reloads the list (onChange), already sorted.
    if (onChange) watch(() => [state.key, state.dir], () => state.server && onChange());
    return state;
}

const text = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

export function sortRows(rows, state) {
    if (state.server) return rows; // already in order from the API
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
export function useSort(source, columns, initial, options) {
    const state = useSortState(columns, initial, options);
    const sorted = computed(() => sortRows((typeof source === 'function' ? source() : source.value) ?? [], state));
    return { sort: state, sorted };
}

/** A time as a number, for sorting. */
export const ts = (v) => (v ? new Date(v).getTime() : null);

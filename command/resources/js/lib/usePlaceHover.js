import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Pointing at (or tabbing into) a table row shows where that person is: their unit's full name,
 * ward and LGA, in a card beside the row's [data-place] cell. A short delay keeps the card from
 * flickering as the pointer crosses the table; scrolling puts it away.
 * Rows need `ward` and `lga`, and `polling_unit` { code, name } when they have a unit.
 */
export function usePlaceHover() {
    const hover = ref(null);
    let timer;
    function show(row, e) {
        clearTimeout(timer);
        const cell = e.currentTarget.querySelector('[data-place]');
        if (!cell || !row.ward) return;
        timer = setTimeout(() => (hover.value = { row, rect: cell.getBoundingClientRect() }), e.type === 'focusin' ? 0 : 250);
    }
    function hide() {
        clearTimeout(timer);
        hover.value = null;
    }
    onMounted(() => window.addEventListener('scroll', hide, true));
    onBeforeUnmount(() => {
        clearTimeout(timer);
        window.removeEventListener('scroll', hide, true);
    });
    return { hover, show, hide };
}

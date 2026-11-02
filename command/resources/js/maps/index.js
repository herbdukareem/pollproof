/**
 * State maps for the overview. The server's POLLPROOF_STATE_MAP names one; only that state's
 * adapter (and its map library) is downloaded, and only on pages that show the map.
 *
 * To add a state: write resources/js/maps/<state>.js exporting the same three things as
 * niger.js, add it below, and set POLLPROOF_STATE_MAP=<state> on that campaign's server.
 *
 *   name         'Niger State'
 *   credit       one line of attribution shown under the map
 *   mount(el, { title, regions, onOpen }) -> { ready: Promise, update(regions), destroy() }
 *
 * `regions` is keyed by LGA name: { value, label, description, color, url }. `onOpen(url)` is
 * called when someone follows a region's link, so the app can route without a page reload.
 */
const ADAPTERS = {
    niger: () => import('./niger.js'),
};

const slug = (s) => String(s ?? '').trim().toLowerCase().replace(/\s+state$/, '').replace(/[^a-z]+/g, '-');

/**
 * The map adapter for this server, if the campaign is in that state. A Kogi campaign on a
 * server set to "niger" shows no map rather than the wrong state.
 */
export function stateMapFor(key, campaignState) {
    const k = slug(key);
    if (!k || !ADAPTERS[k]) return null;
    if (campaignState && slug(campaignState) !== k) return null;
    return ADAPTERS[k];
}

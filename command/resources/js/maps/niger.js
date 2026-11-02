import INISTMap from '@saidabdul80/inistmap';
import '@saidabdul80/inistmap/style.css';
import wardGeoJSON from '@saidabdul80/inistmap/ward-data';

/**
 * Niger State, drawn by INISTMap from GRID3's ward boundaries (25 LGAs, 275 wards).
 * See maps/index.js for the adapter contract.
 */
export const name = 'Niger State';
export const credit = 'Map: INISTMap by Said Abdulsalam. Boundaries: GRID3 / CIESIN operational wards (CC BY-SA 4.0). Wards are GRID3’s, so units are placed in them by GPS.';

// The map's LGA shapes spell two of INEC's LGAs differently (its ward data takes either).
const ALIASES = { Munya: 'Muya', Agaie: 'Agai' };

/** Our LGA data keyed the way the map names its LGA shapes. */
const rename = (regions) => Object.fromEntries(Object.entries(regions ?? {}).map(([k, v]) => [ALIASES[k] ?? k, v]));

// --- Which ward a GPS point falls in -------------------------------------------------------

const shapes = wardGeoJSON.features.map((f) => {
    const polys = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : [f.geometry.coordinates];
    let [x0, y0, x1, y1] = [Infinity, Infinity, -Infinity, -Infinity];
    for (const poly of polys) for (const [x, y] of poly[0]) {
        x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y);
    }
    return { lga: f.properties.lga, ward: f.properties.ward, polys, box: [x0, y0, x1, y1] };
});

function inRing(x, y, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
}
const inPolygon = (x, y, poly) => inRing(x, y, poly[0]) && !poly.slice(1).some((hole) => inRing(x, y, hole));

const located = new Map();
/** The map's LGA and ward for a point, or null outside the state. */
export function locate(lat, lng) {
    const key = `${lat},${lng}`;
    if (located.has(key)) return located.get(key);
    const hit = shapes.find((s) => lng >= s.box[0] && lng <= s.box[2] && lat >= s.box[1] && lat <= s.box[3] && s.polys.some((p) => inPolygon(lng, lat, p)));
    const out = hit ? { lga: hit.lga, ward: hit.ward } : null;
    located.set(key, out);
    return out;
}

// --- The map --------------------------------------------------------------------------------

export function mount(el, { title, regions, wards, onOpen }) {
    const map = new INISTMap(el, {
        title,
        data: rename(regions),
        wardData: wards ?? {},
        wardDrilldown: true, // LGAs drawn from the ward source; tap one to see its wards
        wardGeoJSON,
        variant: 'plain',
        compact: true,
        showSearch: false,
        showStats: false,
        showLegend: false, // colours are set per area; the card draws its own key
        showLabels: true,
        labelMode: 'both',
        extrusionDepth: 6,
        extrusionColor: '#c9d1de',
        popover: true,
        popoverTrigger: 'both',
        wheelZoom: false, // the page scrolls past the map; zoom with the buttons or a pinch
        panOverscroll: 0,
        maxZoom: 40, // the default (8) can't fit small LGAs such as Chanchaga or Suleja in ward view
        colors: ['#eef1f6', '#13213c'],
    });

    // Follow "Open units" inside the app instead of reloading the page.
    const follow = (e) => {
        const a = e.target.closest('a[href]');
        if (!a || !el.contains(a) || a.target === '_blank' || e.metaKey || e.ctrlKey) return;
        const url = new URL(a.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        e.preventDefault();
        onOpen?.(url.pathname + url.search);
    };
    el.addEventListener('click', follow);

    return {
        ready: map.ready,
        // Refresh both levels; whichever is on screen repaints, the other is kept for later.
        update(next, nextWards) {
            map.setData(rename(next), { level: 'lgas' });
            if (nextWards) map.setWardData(nextWards);
        },
        destroy() {
            el.removeEventListener('click', follow);
            map.destroy();
        },
    };
}

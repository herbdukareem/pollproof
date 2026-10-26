<script setup>
// Small stroke icon set, so the app needs no icon dependency.
const props = defineProps({ name: { type: String, required: true }, size: { type: Number, default: 18 } });
const paths = {
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    pin: 'M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    check: 'M20 6L9 17l-5-5',
    layers: 'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5',
    edit: 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
    alert: 'M12 3L2 20h20L12 3zM12 10v4M12 17h.01',
    users: 'M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 20v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    wallet: 'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2',
    shield: 'M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3zM9 12l2 2 4-4',
    logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
    menu: 'M4 6h16M4 12h16M4 18h16',
    refresh: 'M20 12a8 8 0 0 1-14 5.3M4 12A8 8 0 0 1 18 6.7M18 3v4h-4M6 21v-4h4',
    chevron: 'M9 5l7 7-7 7',
    download: 'M12 4v11M7 10l5 5 5-5M5 20h14',
    x: 'M6 6l12 12M18 6L6 18',
    image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 9h.01',
};
</script>

<template>
    <svg :width="props.size" :height="props.size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[props.name]" />
    </svg>
</template>

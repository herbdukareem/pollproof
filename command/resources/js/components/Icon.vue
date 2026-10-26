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
    flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
    map: 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14',
    list: 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
    tree: 'M12 3v5M5 13v-2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2M12 8v2M3 13h4v4H3zM10 13h4v4h-4zM17 13h4v4h-4z',
    key: 'M15 7a4 4 0 1 1-3.9 4.9L4 19v2h3v-2h2v-2h2l1.1-1.1A4 4 0 0 1 15 7zM16 10h.01',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
    upload: 'M12 20V9M7 14l5-5 5 5M5 4h14',
    send: 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
    plus: 'M12 5v14M5 12h14',
    lock: 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
};
</script>

<template>
    <svg :width="props.size" :height="props.size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[props.name]" />
    </svg>
</template>

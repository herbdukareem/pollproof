<script setup>
import { computed } from 'vue';
import { api } from '../lib/api';

/**
 * One video from the field, played in the page. The file streams from the server with range
 * requests, so it starts quickly and can be skipped through. `video` needs an `id`.
 */
const props = defineProps({ video: { type: Object, required: true }, autoplay: Boolean });
const src = computed(() => api.url(`videos/${props.video.id}/file`));
</script>

<template>
    <video
        :key="video.id"
        class="block aspect-video w-full rounded-lg bg-[#0d1626] object-contain"
        :src="src"
        controls
        playsinline
        preload="metadata"
        :autoplay="autoplay"
        controlslist="nodownload"
    ></video>
</template>

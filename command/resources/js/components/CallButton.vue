<script setup>
import { ref } from 'vue';
import Icon from './Icon.vue';
import { callOrCopy } from '../lib/call';

/** Call an agent: opens the dialler on a phone, copies the number on a computer. */
const props = defineProps({ phone: { type: String, required: true }, who: { type: String, default: '' }, small: Boolean });
const copied = ref(false);
let timer;

async function click(e) {
    if (!(await callOrCopy(e, props.phone, props.who))) return;
    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2500);
}
</script>

<template>
    <a :href="`tel:${phone.replace(/\s+/g, '')}`" class="btn-outline gap-1.5 whitespace-nowrap" :class="small ? 'h-8 px-3 text-xs' : ''" :title="`Call ${phone} (copies the number on a computer)`" @click="click">
        <Icon :name="copied ? 'check' : 'phone'" :size="small ? 14 : 16" />
        <span>{{ copied ? 'Copied' : 'Call' }}</span>
    </a>
</template>

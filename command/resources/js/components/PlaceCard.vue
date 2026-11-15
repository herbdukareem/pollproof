<script setup>
import HoverCard from './HoverCard.vue';

/** The card from usePlaceHover: a unit's code and full name, then its ward and LGA. */
defineProps({ hover: { type: Object, default: null } });
</script>

<template>
    <HoverCard :anchor="hover?.rect">
        <template v-if="hover">
            <div v-if="hover.row.polling_unit" class="mb-2 border-b border-rule pb-2">
                <div class="fig font-[650] text-ink">{{ hover.row.polling_unit.code }}</div>
                <div class="text-ink-2">{{ hover.row.polling_unit.name }}</div>
            </div>
            <dl class="grid grid-cols-[52px_1fr] gap-x-3 gap-y-1">
                <dt class="text-ink-3">Ward</dt>
                <dd class="font-[600] text-ink">{{ hover.row.ward }}</dd>
                <dt class="text-ink-3">LGA</dt>
                <dd class="font-[600] text-ink">{{ hover.row.lga }}</dd>
            </dl>
        </template>
    </HoverCard>
</template>

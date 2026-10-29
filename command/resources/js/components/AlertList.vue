<script setup>
import Badge from './Badge.vue';
import Icon from './Icon.vue';
import { dateTime, time } from '../lib/format';

// compact: one line for the title and one for the detail (the overview); otherwise full text and who resolved it.
defineProps({ alerts: { type: Array, default: () => [] }, canResolve: Boolean, busy: Number, compact: Boolean });
defineEmits(['resolve']);
const tone = { high: 'bad', medium: 'warn', low: 'neutral' };
const bar = { high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' };
</script>

<template>
    <TransitionGroup tag="ul" name="alert" class="relative flex flex-col gap-2">
        <li v-for="a in alerts" :key="a.id" class="flex items-stretch gap-3 rounded-xl bg-[#f6f7f9] py-2.5 pr-2.5 pl-3 transition-colors hover:bg-[#eef0f4]" :class="a.status === 'resolved' ? 'opacity-70' : ''">
            <span class="w-1 shrink-0 rounded-full" :class="bar[a.severity]"></span>
            <component :is="a.link ? 'RouterLink' : 'div'" :to="a.link" class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <Badge :tone="tone[a.severity]">{{ a.severity }}</Badge>
                    <span class="min-w-0 text-[13.5px] leading-snug font-bold" :class="compact ? 'truncate' : ''" :title="a.title">{{ a.title }}</span>
                    <span class="ml-auto shrink-0 pl-1 text-xs whitespace-nowrap text-ink-2">{{ time(a.updated_at) }}</span>
                </div>
                <p class="mt-0.5 text-[12.5px] leading-snug text-ink-2" :class="compact ? 'truncate' : ''" :title="compact ? a.detail : null">{{ a.detail }}</p>
                <p v-if="!compact" class="mt-1 text-[12px] text-ink-3">
                    {{ a.label }} · raised {{ dateTime(a.created_at) }}<template v-if="a.status === 'resolved'"> · resolved {{ dateTime(a.resolved_at) }}{{ a.resolved_by ? ' by ' + a.resolved_by : '' }}</template>
                </p>
            </component>
            <div v-if="a.status === 'resolved'" class="flex shrink-0 items-center"><Badge tone="good">Resolved</Badge></div>
            <div v-else-if="canResolve" class="flex shrink-0 items-center">
                <button
                    v-if="compact"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-[#d6dae1] bg-white text-ink-2 transition hover:border-good hover:bg-good-bg hover:text-good active:scale-90 disabled:opacity-50"
                    :disabled="busy === a.id"
                    :title="'Mark resolved: ' + a.title"
                    :aria-label="'Mark resolved: ' + a.title"
                    @click="$emit('resolve', a)"
                ><Icon name="check" :size="16" :class="busy === a.id ? 'animate-pulse' : ''" /></button>
                <button v-else class="btn-ghost h-9" :disabled="busy === a.id" @click="$emit('resolve', a)"><Icon name="check" :size="15" /> Resolve</button>
            </div>
        </li>
    </TransitionGroup>
</template>

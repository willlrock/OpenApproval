<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    title: string;
    items: { id: number; name: string; inactive?: boolean }[];
    empty: string;
}>();
const expanded = ref(false);
const contentId = useId();
const remaining = computed(() => props.items.slice(1));
</script>

<template>
    <div class="min-w-0">
        <p class="mb-1 font-medium">{{ title }} ({{ items.length }})</p>
        <div
            v-if="items.length"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 sm:min-h-11"
        >
            <Badge
                variant="outline"
                class="max-w-full text-sm font-normal [overflow-wrap:anywhere] whitespace-normal"
                :class="items[0].inactive && 'line-through opacity-50'"
                >{{ items[0].name }}</Badge
            >
            <Button
                v-if="remaining.length"
                variant="ghost"
                class="min-h-11 shrink-0 gap-1 px-2 text-sm"
                :aria-expanded="expanded"
                :aria-controls="contentId"
                :aria-label="`${expanded ? 'Свернуть' : 'Показать все'}: ${title}`"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Свернуть' : `Ещё ${remaining.length}`
                }}<ChevronDown
                    class="size-4"
                    :class="expanded && 'rotate-180'"
                />
            </Button>
        </div>
        <p v-else class="sm:min-h-11 sm:content-center">{{ empty }}</p>
        <div
            :id="contentId"
            v-show="expanded && remaining.length"
            class="mt-1 flex flex-wrap gap-1.5"
        >
            <Badge
                v-for="item in remaining"
                :key="item.id"
                variant="outline"
                class="max-w-full text-sm font-normal [overflow-wrap:anywhere] whitespace-normal"
                :class="item.inactive && 'line-through opacity-50'"
                >{{ item.name }}</Badge
            >
        </div>
    </div>
</template>

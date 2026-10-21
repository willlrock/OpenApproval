<script setup lang="ts">
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const nextAppearance = computed(() => {
    if (appearance.value === 'light') return 'dark';
    if (appearance.value === 'dark') return 'system';
    return 'light';
});

const label = computed(() => {
    if (appearance.value === 'light') return 'Светлая тема';
    if (appearance.value === 'dark') return 'Тёмная тема';
    return 'Системная тема';
});

function toggle() {
    updateAppearance(nextAppearance.value);
}
</script>

<template>
    <TooltipProvider :delay-duration="0">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-9 w-9 cursor-pointer"
                    :aria-label="label + '. Переключить тему'"
                    @click="toggle"
                >
                    <Sun v-if="appearance === 'light'" class="h-4 w-4" />
                    <Moon v-else-if="appearance === 'dark'" class="h-4 w-4" />
                    <Monitor v-else class="h-4 w-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                <p>{{ label }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>

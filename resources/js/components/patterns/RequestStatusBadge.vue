<script setup lang="ts">
import { computed } from 'vue';
import { requestStatusPresentation } from '@/lib/requestPresentation';
import StatusBadge from './StatusBadge.vue';
const props = defineProps<{ status: string }>();
const presentation = computed(() => requestStatusPresentation(props.status));
</script>
<template>
    <StatusBadge :label="presentation.label" :tone="presentation.tone" />
</template>

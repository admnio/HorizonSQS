<script setup>
import { computed } from 'vue';

const props = defineProps({
  points: { type: Array, default: () => [] },  // normalized 0..1 array
});

const path = computed(() => {
  if (props.points.length === 0) return '';
  return props.points
    .map((p, i) => `${(i / Math.max(1, props.points.length - 1)) * 100},${20 - (p * 20)}`)
    .join(' ');
});
</script>

<template>
  <svg viewBox="0 0 100 20" preserveAspectRatio="none" class="w-full h-4">
    <polyline :points="path" fill="none" stroke="currentColor" stroke-width="1" />
  </svg>
</template>

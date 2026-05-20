<script setup>
defineProps({
  message: { type: String, default: 'Nothing here yet.' },
});
</script>

<template>
  <div class="text-center text-sunset-muted py-8 text-xs font-mono">{{ message }}</div>
</template>

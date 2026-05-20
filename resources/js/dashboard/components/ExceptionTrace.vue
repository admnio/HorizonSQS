<script setup>
defineProps({
  exception: { type: String, required: true },
});
</script>

<template>
  <pre class="bg-sunset-bg border border-sunset-border rounded p-3 text-[11px] text-status-error overflow-auto whitespace-pre-wrap font-mono">{{ exception }}</pre>
</template>

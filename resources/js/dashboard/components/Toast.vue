<script setup>
defineProps({
  message: { type: String, required: true },
  type:    { type: String, default: 'info' },  // 'info' | 'error' | 'success'
});

const classes = {
  info:    'bg-sunset-card text-sunset-text border border-sunset-border',
  error:   'bg-status-error text-white',
  success: 'bg-status-ok text-white',
};
</script>

<template>
  <div :class="['fixed bottom-4 right-4 px-3 py-2 rounded text-xs font-mono shadow-lg z-50', classes[type] || classes.info]">
    {{ message }}
  </div>
</template>

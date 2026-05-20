<script setup>
const props = defineProps({
  status: { type: String, default: 'info' },  // 'failed' | 'ok' | 'warn' | 'info'
});

const cls = {
  failed: 'bg-status-error/20 text-status-error',
  ok:     'bg-status-ok/20 text-status-ok',
  warn:   'bg-status-warn/20 text-status-warn',
  info:   'bg-status-info/20 text-status-info',
};
</script>

<template>
  <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-mono uppercase', cls[status] || cls.info]">
    <slot>{{ status }}</slot>
  </span>
</template>

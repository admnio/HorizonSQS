<script setup>
import { ref } from 'vue';

defineProps({
  label:        { type: String, required: true },
  confirmLabel: { type: String, default: 'Confirm' },
  variant:      { type: String, default: 'default' },  // 'default' | 'danger'
});
const emit = defineEmits(['confirm']);

const armed = ref(false);
let timer = null;

function arm() {
  armed.value = true;
  if (timer) clearTimeout(timer);
  timer = setTimeout(() => { armed.value = false; }, 3000);
}

function confirm() {
  armed.value = false;
  if (timer) clearTimeout(timer);
  emit('confirm');
}
</script>

<template>
  <button
    v-if="! armed"
    @click="arm"
    :class="[
      'px-2 py-1 rounded text-xs font-mono border',
      'bg-sunset-card border-sunset-border text-sunset-muted hover:text-sunset-text',
    ]"
  >{{ label }}</button>
  <button
    v-else
    @click="confirm"
    :class="[
      'px-2 py-1 rounded text-xs font-mono',
      variant === 'danger' ? 'bg-status-error text-white' : 'bg-sunset-accent text-sunset-bg',
    ]"
  >{{ confirmLabel }}</button>
</template>

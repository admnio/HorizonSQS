<script setup>
import { useTheme } from '../composables/useTheme.js';
import { usePaletteStore } from '../stores/paletteStore.js';

const theme = useTheme();
const palette = usePaletteStore();
</script>

<template>
  <header class="h-12 bg-sunset-rail border-b border-sunset-border px-4 flex items-center gap-4 text-xs font-mono">
    <span class="text-sunset-accent font-bold">sunset</span>
    <button
      @click="palette.toggle"
      class="ml-auto px-2 py-1 bg-sunset-card border border-sunset-border rounded text-sunset-muted hover:text-sunset-text"
    >⌘K  jump</button>
    <button
      @click="theme.toggle"
      class="px-2 py-1 bg-sunset-card border border-sunset-border rounded text-sunset-muted hover:text-sunset-text"
      aria-label="Toggle theme"
    >theme</button>
  </header>
</template>

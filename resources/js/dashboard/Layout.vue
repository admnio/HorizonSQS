<script setup>
import LeftRail from './components/LeftRail.vue';
import HeaderBar from './components/Header.vue';
import CommandPalette from './components/CommandPalette.vue';
import { useKeyboard } from './composables/useKeyboard.js';

useKeyboard();
</script>

<template>
  <div class="min-h-screen bg-sunset-bg text-sunset-text font-mono">
    <HeaderBar />
    <div class="flex">
      <LeftRail class="hidden md:block" />
      <main class="flex-1 p-4">
        <slot />
      </main>
    </div>
    <CommandPalette />
  </div>
</template>

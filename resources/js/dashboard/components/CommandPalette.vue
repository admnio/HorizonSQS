<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import Fuse from 'fuse.js';
import { usePaletteStore } from '../stores/paletteStore.js';

const palette = usePaletteStore();
const query = ref('');
const input = ref(null);
const activeIndex = ref(0);

const entries = [
  { label: 'Overview',        href: '/sunset' },
  { label: 'Workload',        href: '/sunset/workload' },
  { label: 'Recent jobs',     href: '/sunset/jobs/recent' },
  { label: 'Failed jobs',     href: '/sunset/jobs/failed' },
  { label: 'Pending jobs',    href: '/sunset/jobs/pending' },
  { label: 'Completed jobs',  href: '/sunset/jobs/completed' },
  { label: 'Metrics',         href: '/sunset/metrics' },
  { label: 'Monitoring',      href: '/sunset/monitoring' },
  { label: 'Rate limits',     href: '/sunset/rate-limits' },
  { label: 'Supervisors',     href: '/sunset/supervisors' },
  { label: 'Batches',         href: '/sunset/batches' },
  { label: 'Health',          href: '/sunset/health' },
];

const fuse = new Fuse(entries, { keys: ['label'], threshold: 0.3 });
const results = computed(() => query.value ? fuse.search(query.value).map(r => r.item) : entries);

watch(() => palette.open, async (open) => {
  if (open) {
    query.value = '';
    activeIndex.value = 0;
    await nextTick();
    input.value?.focus();
  }
});

function pick(href) {
  palette.hide();
  router.visit(href);
}

function onKey(e) {
  if (! palette.open) return;
  if (e.key === 'ArrowDown' || e.key === 'j' && e.ctrlKey) {
    e.preventDefault();
    activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1);
  } else if (e.key === 'ArrowUp' || e.key === 'k' && e.ctrlKey) {
    e.preventDefault();
    activeIndex.value = Math.max(activeIndex.value - 1, 0);
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const r = results.value[activeIndex.value];
    if (r) pick(r.href);
  }
}
</script>

<template>
  <div
    v-if="palette.open"
    @click.self="palette.hide"
    @keydown="onKey"
    class="fixed inset-0 z-50 flex items-start justify-center pt-32 bg-black/50"
  >
    <div class="bg-sunset-card border border-sunset-border rounded-md w-[500px] max-w-[90vw] font-mono text-sm shadow-2xl">
      <input
        ref="input"
        v-model="query"
        class="w-full bg-transparent border-b border-sunset-border px-4 py-3 text-sunset-text outline-none placeholder:text-sunset-muted"
        placeholder="Jump to page…"
      >
      <div class="max-h-72 overflow-auto">
        <button
          v-for="(r, i) in results"
          :key="r.href"
          @click="pick(r.href)"
          @mouseenter="activeIndex = i"
          :class="[
            'w-full text-left px-4 py-2 text-sunset-text',
            activeIndex === i ? 'bg-sunset-rail' : ''
          ]"
        >{{ r.label }}</button>
        <div v-if="results.length === 0" class="px-4 py-3 text-sunset-muted text-xs">No matches</div>
      </div>
    </div>
  </div>
</template>

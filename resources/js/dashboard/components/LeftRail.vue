<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const currentPath = computed(() => page.url || (typeof window !== 'undefined' ? window.location.pathname : ''));

const groups = [
  { label: 'Overview', items: [
    { href: '/sunset',           label: 'home' },
    { href: '/sunset/workload',  label: 'workload' },
    { href: '/sunset/metrics',   label: 'metrics' },
  ]},
  { label: 'Jobs', items: [
    { href: '/sunset/jobs/recent',    label: 'recent' },
    { href: '/sunset/jobs/failed',    label: 'failed' },
    { href: '/sunset/jobs/pending',   label: 'pending' },
    { href: '/sunset/jobs/completed', label: 'completed' },
    { href: '/sunset/batches',        label: 'batches' },
  ]},
  { label: 'Ops', items: [
    { href: '/sunset/supervisors', label: 'supervisors' },
    { href: '/sunset/rate-limits', label: 'limits' },
    { href: '/sunset/monitoring',  label: 'monitoring' },
    { href: '/sunset/health',      label: 'health' },
  ]},
];

function isActive(href) {
  const path = currentPath.value || '';
  if (href === '/sunset') {
    return path === '/sunset' || path === '/sunset/';
  }
  return path === href;
}
</script>

<template>
  <nav class="w-[160px] min-h-[calc(100vh-48px)] bg-sunset-rail border-r border-sunset-border text-xs font-mono p-3 shrink-0">
    <div v-for="group in groups" :key="group.label" class="mb-4">
      <div class="text-[9px] uppercase tracking-wide text-sunset-muted mb-1">{{ group.label }}</div>
      <Link
        v-for="item in group.items"
        :key="item.href"
        :href="item.href"
        :class="[
          'block px-2 py-1 rounded transition-colors',
          isActive(item.href)
            ? 'bg-sunset-card text-sunset-accent'
            : 'text-sunset-muted hover:text-sunset-text'
        ]"
      >{{ item.label }}</Link>
    </div>
  </nav>
</template>

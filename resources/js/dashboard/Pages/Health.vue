<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import StatusPill from '../components/StatusPill.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const config = computed(() => current.value.config ?? {});
const transports = computed(() => current.value.transports ?? {});
const version = computed(() => current.value.version ?? '');
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-base font-bold">Health</h1>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Version</h2>
      <div class="text-sm tabular-nums">sunset {{ version }}</div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Transports</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div
          v-for="(t, name) in transports"
          :key="name"
          class="border border-sunset-border rounded p-3 bg-sunset-card"
        >
          <div class="flex items-center justify-between mb-1">
            <span class="font-bold">{{ name }}</span>
            <StatusPill :status="t.configured ? 'ok' : 'warn'">
              {{ t.configured ? 'configured' : 'unconfigured' }}
            </StatusPill>
          </div>
          <div class="text-[10px] text-sunset-muted">driver: {{ t.driver || '—' }}</div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Resolved sunset config</h2>
      <pre class="bg-sunset-card border border-sunset-border rounded p-3 text-[11px] overflow-auto max-h-96">{{ JSON.stringify(config, null, 2) }}</pre>
    </section>
  </div>
</template>

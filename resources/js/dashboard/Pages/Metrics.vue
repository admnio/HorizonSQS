<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

// Controller returns:
//   jobs            : array of measured job class names
//   queues          : array of measured queue names
//   snapshot_taken_at: int (unix seconds, 0 if never)
//   wait_times      : array<queue, seconds>
const jobNames = computed(() => current.value.jobs ?? []);
const queueNames = computed(() => current.value.queues ?? []);
const snapshotAt = computed(() => current.value.snapshot_taken_at ?? 0);
const waitTimes = computed(() => current.value.wait_times ?? {});

const snapshotLabel = computed(() => {
  const ts = snapshotAt.value;
  if (! ts) return 'never';
  return new Date(ts * 1000).toLocaleString();
});

function fetchJobSeries(name) {
  // Future enhancement hook: GET /sunset/metrics/jobs/:name returns
  // { snapshots, throughput, runtime } for inline detail. The index
  // currently just lists names + the latest snapshot timestamp.
  return `/sunset/metrics/jobs/${encodeURIComponent(name)}`;
}

function fetchQueueSeries(name) {
  return `/sunset/metrics/queues/${encodeURIComponent(name)}`;
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-baseline justify-between">
      <h1 class="text-base font-bold">Metrics</h1>
      <span class="text-[10px] text-sunset-muted">last snapshot: {{ snapshotLabel }}</span>
    </div>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Queues ({{ queueNames.length }})</h2>
      <Empty v-if="queueNames.length === 0" message="No queue metrics recorded yet." />
      <div v-else class="border border-sunset-border rounded divide-y divide-sunset-border">
        <div
          v-for="name in queueNames"
          :key="`q:${name}`"
          class="px-3 py-2 grid grid-cols-[1fr_120px] items-center gap-3 text-xs"
        >
          <div class="truncate font-bold">{{ name }}</div>
          <div class="text-sunset-muted text-right tabular-nums">
            <span v-if="waitTimes[name] !== undefined">wait {{ waitTimes[name] }}s</span>
            <span v-else>—</span>
          </div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Jobs ({{ jobNames.length }})</h2>
      <Empty v-if="jobNames.length === 0" message="No job metrics recorded yet." />
      <div v-else class="border border-sunset-border rounded divide-y divide-sunset-border">
        <div
          v-for="name in jobNames"
          :key="`j:${name}`"
          class="px-3 py-2 text-xs truncate"
        >
          {{ name }}
        </div>
      </div>
    </section>
  </div>
</template>

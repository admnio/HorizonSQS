<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';
import Sparkline from '../components/Sparkline.vue';

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

// Per-name normalized point caches. Cleared whenever the snapshot timestamp
// advances so sparklines reflect fresh data without a page reload.
const jobSeries = ref({});
const queueSeries = ref({});

// Batched fetch: a single request for every name on the page rather than one
// HTTP round-trip per job/queue. Apps with hundreds of job classes pay a
// significant cost on mount otherwise.
async function loadAllSeries() {
  const params = new URLSearchParams();
  for (const j of jobNames.value) params.append('jobs[]', j);
  for (const q of queueNames.value) params.append('queues[]', q);

  if (! params.toString()) return;

  try {
    const resp = await axios.get('/sunset/metrics/series', { params });
    jobSeries.value = { ...jobSeries.value, ...(resp.data?.jobs || {}) };
    queueSeries.value = { ...queueSeries.value, ...(resp.data?.queues || {}) };
  } catch (e) {
    // Leave any previously-loaded series in place; missing series stay missing.
  }
}

onMounted(loadAllSeries);

// When a new snapshot lands (or the measured-name lists change), refetch the
// per-name series so the sparklines stay in sync with the polled summary.
watch(snapshotAt, loadAllSeries);
watch([jobNames, queueNames], loadAllSeries, { flush: 'post' });
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
          class="px-3 py-2 grid grid-cols-[1fr_140px_120px] items-center gap-3 text-xs"
        >
          <div class="truncate font-bold">{{ name }}</div>
          <div class="text-sunset-accent">
            <Sparkline :points="queueSeries[name] || []" />
          </div>
          <div class="text-sunset-muted text-right tabular-nums">
            <span v-if="waitTimes[name] !== undefined">wait {{ waitTimes[name] }}s</span>
            <span v-else>&mdash;</span>
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
          class="px-3 py-2 grid grid-cols-[1fr_140px] items-center gap-3 text-xs"
        >
          <div class="truncate font-mono">{{ name }}</div>
          <div class="text-sunset-accent">
            <Sparkline :points="jobSeries[name] || []" />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

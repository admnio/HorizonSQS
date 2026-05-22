<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import Empty from '../components/Empty.vue';
import Sparkline from '../components/Sparkline.vue';
import RangeGroup from '../components/RangeGroup.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

// Controller payload (v1):
//   jobs, queues, snapshot_taken_at, wait_times
const jobNames = computed(() => current.value.jobs ?? []);
const queueNames = computed(() => current.value.queues ?? []);
const snapshotAt = computed(() => current.value.snapshot_taken_at ?? 0);
const waitTimes = computed(() => current.value.wait_times ?? {});

const range = ref('1h');

// Per-name normalized point caches.
const jobSeries = ref({});
const queueSeries = ref({});

async function loadAllSeries() {
  const params = new URLSearchParams();
  for (const j of jobNames.value) params.append('jobs[]', j);
  for (const q of queueNames.value) params.append('queues[]', q);
  if (!params.toString()) return;

  try {
    const resp = await axios.get('/sunset/metrics/series', { params });
    jobSeries.value = { ...jobSeries.value, ...(resp.data?.jobs || {}) };
    queueSeries.value = { ...queueSeries.value, ...(resp.data?.queues || {}) };
  } catch (e) {
    /* keep prior series */
  }
}

onMounted(loadAllSeries);
watch(snapshotAt, loadAllSeries);
watch([jobNames, queueNames], loadAllSeries, { flush: 'post' });

// TODO(v2-wire-data): Aggregate hero stats not yet on the controller payload.
// Phase 7 will extend MetricsController; placeholders for now.
const heroStats = computed(() => current.value.summary ?? {
  jobs_per_min: '—',
  jobs_per_hour: '—',
  avg_runtime_ms: '—',
  p99_runtime_ms: null,
  failure_rate_pct: '—',
  failures_last_hour: 0,
});

const jobsPerMinSpark = [0.3, 0.4, 0.42, 0.55, 0.6, 0.7, 0.75, 0.8, 0.85, 0.9];
const jobsPerHourSpark = [0.5, 0.55, 0.5, 0.58, 0.62, 0.6, 0.7, 0.75, 0.78, 0.82];
const avgRuntimeSpark = [0.5, 0.48, 0.55, 0.5, 0.52, 0.55, 0.5, 0.48, 0.52, 0.5];
const failureSpark = [0.15, 0.2, 0.18, 0.12, 0.2, 0.1, 0.18, 0.08, 0.05];

// Build by-queue rows with computed avg + sparkline data
const queueRows = computed(() =>
  queueNames.value.map((name) => ({
    name,
    connection: '—',  // TODO(v2-wire-data): controller doesn't expose connection per queue here
    jobs_per_min: heroStats.value.queue_rates?.[name] ?? '—',
    avg_ms: heroStats.value.queue_avg_ms?.[name] ?? '—',
    p99_ms: heroStats.value.queue_p99_ms?.[name] ?? '—',
    failures: heroStats.value.queue_failures?.[name] ?? 0,
    wait: waitTimes.value[name] ?? null,
    series: queueSeries.value[name] || [],
  })),
);

const jobRows = computed(() =>
  jobNames.value.map((name) => ({
    name,
    queue: heroStats.value.job_queue?.[name] ?? '—',
    jobs_per_min: heroStats.value.job_rates?.[name] ?? '—',
    avg_ms: heroStats.value.job_avg_ms?.[name] ?? '—',
    p99_ms: heroStats.value.job_p99_ms?.[name] ?? '—',
    failures: heroStats.value.job_failures?.[name] ?? 0,
    series: jobSeries.value[name] || [],
  })),
);

function onJobRowClick(row) {
  router.visit(`/sunset/metrics/jobs/${encodeURIComponent(row.name)}`);
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Metrics</h1>
      <span class="page-sub">throughput · latency · failures</span>
      <div class="page-actions">
        <RangeGroup v-model="range" :options="['15m','1h','6h','24h','7d']" />
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-label" v-tooltip="'Jobs completed (or failed) per minute, rolling average'">
          <svg><use href="#i-zap"/></svg>Jobs / minute
        </div>
        <div class="stat-value">{{ heroStats.jobs_per_min }}</div>
        <div class="stat-delta">live</div>
        <Sparkline :points="jobsPerMinSpark" color="violet" :width="92" :height="28" class="stat-spark" />
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Cumulative jobs in the last hour'">
          <svg><use href="#i-chart"/></svg>Jobs / hour
        </div>
        <div class="stat-value">{{ heroStats.jobs_per_hour }}</div>
        <div class="stat-delta">peak today</div>
        <Sparkline :points="jobsPerHourSpark" color="blue" :width="92" :height="28" class="stat-spark" />
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Average job runtime across all classes'">
          <svg><use href="#i-clock"/></svg>Avg runtime
        </div>
        <div class="stat-value">
          {{ heroStats.avg_runtime_ms }}<span class="unit">ms</span>
        </div>
        <div class="stat-delta">p99 {{ heroStats.p99_runtime_ms ?? '—' }}ms</div>
        <Sparkline :points="avgRuntimeSpark" color="amber" :width="92" :height="28" class="stat-spark" />
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'(failed / total) × 100, last 1 hour'">
          <svg><use href="#i-alert"/></svg>Failure rate
        </div>
        <div class="stat-value red">{{ heroStats.failure_rate_pct }}<span class="unit" style="color: rgb(var(--red)); opacity: 0.7;">%</span></div>
        <div class="stat-delta">{{ heroStats.failures_last_hour }} in last hour</div>
        <Sparkline :points="failureSpark" color="red" :width="92" :height="28" class="stat-spark" />
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Throughput by queue</h2>
        <span class="meta">{{ queueRows.length }} queue{{ queueRows.length === 1 ? '' : 's' }}</span>
      </div>
      <Empty v-if="queueRows.length === 0" message="No queue metrics recorded yet." />
      <DataTable
        v-else
        :columns="[
          { key: 'name', label: 'Queue', width: '1fr', sortable: 'text' },
          { key: 'jobs_per_min', label: 'Jobs/min', width: '120px', align: 'right', sortable: 'num' },
          { key: 'avg_ms', label: 'Avg (ms)', width: '110px', align: 'right', sortable: 'num' },
          { key: 'p99_ms', label: 'p99 (ms)', width: '110px', align: 'right', sortable: 'num' },
          { key: 'failures', label: 'Failures', width: '100px', align: 'right', sortable: 'num' },
          { key: 'trend', label: 'Trend', width: '120px' },
        ]"
        :rows="queueRows"
        :selectable="false"
      >
        <template #name="{ row }">
          <span class="q-name">{{ row.name }}</span>
          <span class="q-conn">{{ row.connection }}</span>
        </template>
        <template #trend="{ row }">
          <Sparkline :points="row.series" color="violet" :width="72" :height="18" />
        </template>
      </DataTable>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Throughput by job class</h2>
        <span class="meta">{{ jobRows.length }} class{{ jobRows.length === 1 ? '' : 'es' }} · click a row to drill in</span>
      </div>
      <Empty v-if="jobRows.length === 0" message="No job metrics recorded yet." />
      <DataTable
        v-else
        :columns="[
          { key: 'name', label: 'Job class', width: '1.4fr', sortable: 'text' },
          { key: 'queue', label: 'Queue', width: '130px' },
          { key: 'jobs_per_min', label: 'Jobs/min', width: '110px', align: 'right', sortable: 'num' },
          { key: 'avg_ms', label: 'Avg (ms)', width: '110px', align: 'right', sortable: 'num' },
          { key: 'p99_ms', label: 'p99 (ms)', width: '110px', align: 'right', sortable: 'num' },
          { key: 'failures', label: 'Failures', width: '100px', align: 'right', sortable: 'num' },
          { key: 'trend', label: 'Trend', width: '120px' },
        ]"
        :rows="jobRows"
        :clickable="true"
        :selectable="false"
        @row-click="onJobRowClick"
      >
        <template #name="{ row }">
          <span class="q-name">{{ row.name }}</span>
        </template>
        <template #queue="{ row }">
          <span class="pill neutral">{{ row.queue }}</span>
        </template>
        <template #trend="{ row }">
          <Sparkline :points="row.series" color="violet" :width="72" :height="18" />
        </template>
      </DataTable>
    </div>

    <div class="callout">
      Per-class metrics are recorded under <code>sunset:metrics:job:{class}</code> Redis hashes,
      trimmed to the last hour by default. Throughput and runtimes are tracked separately from
      per-queue series, so a job class that runs on multiple queues shows its true aggregate here.
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import Empty from '../components/Empty.vue';
import StatusPill from '../components/StatusPill.vue';
import ConfirmAction from '../components/ConfirmAction.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);
const queues = computed(() => current.value.queues ?? []);
const pausedQueues = computed(() => current.value.paused_queues ?? []);

const totalPending = computed(() =>
  queues.value.reduce((s, q) => s + Number(q.length ?? 0), 0),
);
const totalProcesses = computed(() =>
  queues.value.reduce((s, q) => s + Number(q.processes ?? 0), 0),
);
// TODO(v2-wire-data): controller doesn't yet expose worker-slot capacity nor
// throughput-per-queue. Phase 7 will add them; placeholders for now.
const workerCapacity = computed(() => current.value.worker_capacity ?? 46);
const weightedWait = computed(() => {
  const total = queues.value.reduce((s, q) => s + Number(q.length ?? 0), 0);
  if (!total) return 0;
  const weighted = queues.value.reduce(
    (s, q) => s + Number(q.length ?? 0) * Number(q.wait ?? 0),
    0,
  );
  return Math.round(weighted / total);
});
const etaDrainSecs = computed(() => {
  const tp = current.value.throughput_per_min ?? 1200;
  if (!tp || !totalPending.value) return 0;
  return Math.round((totalPending.value / tp) * 60);
});
function fmtEta(s) {
  if (!s) return '—';
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  return m < 60 ? `${m}m` : `${(s / 3600).toFixed(1)}h`;
}

// v1.3.0 — per-queue pause/resume state.
function isPaused(row) {
  const paused = pausedQueues.value;
  if (row.connection) {
    return paused.some((p) => p.connection === row.connection && p.queue === row.name);
  }
  return paused.some((p) => p.queue === row.name);
}
function pauseTarget(row) {
  if (row.connection) return { connection: row.connection, queue: row.name };
  const match = pausedQueues.value.find((p) => p.queue === row.name);
  return match ? { connection: match.connection, queue: match.queue } : null;
}
function togglePause(row) {
  const target = pauseTarget(row);
  if (!target) return;
  const action = isPaused(row) ? 'resume' : 'pause';
  const url = `/sunset/workload/${encodeURIComponent(target.connection)}/${encodeURIComponent(target.queue)}/${action}`;
  router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Workload</h1>
      <span class="page-sub">{{ queues.length }} queue{{ queues.length === 1 ? '' : 's' }}</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg>Refresh</button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-label" v-tooltip="'Sum of pending jobs across all queues'">
          <svg><use href="#i-list"/></svg>Total pending
        </div>
        <div class="stat-value">{{ totalPending.toLocaleString() }}</div>
        <div class="stat-delta">across all queues</div>
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Worker processes actively popping jobs'">
          <svg><use href="#i-server"/></svg>Active workers
        </div>
        <div class="stat-value">
          {{ totalProcesses }}
          <span class="unit">/ {{ workerCapacity }}</span>
        </div>
        <div class="stat-delta">
          {{ workerCapacity ? Math.round((totalProcesses / workerCapacity) * 100) : 0 }}% utilization
        </div>
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Length-weighted average wait across all queues'">
          <svg><use href="#i-clock"/></svg>Weighted wait
        </div>
        <div class="stat-value">
          {{ weightedWait }}<span class="unit">s</span>
        </div>
        <div class="stat-delta">vs front-of-queue</div>
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Approximate time to drain all queues at current throughput'">
          <svg><use href="#i-zap"/></svg>ETA to drain
        </div>
        <div class="stat-value">{{ fmtEta(etaDrainSecs) }}</div>
        <div class="stat-delta">at current rate</div>
      </div>
    </div>

    <div class="section-head" style="margin-top: 32px;">
      <h2>Queues</h2>
      <span class="meta">click pause to halt without restarting workers</span>
    </div>

    <Empty v-if="queues.length === 0" message="No queues configured." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Queue', width: '1fr' },
        { key: 'length', label: 'Length', width: '110px', align: 'right' },
        { key: 'processes', label: 'Workers', width: '100px', align: 'right' },
        { key: 'wait', label: 'Wait (s)', width: '100px', align: 'right' },
        { key: 'actions', label: '', width: '150px' },
      ]"
      :rows="queues"
      :selectable="false"
    >
      <template #name="{ row }">
        <div class="flex items-center gap-2 min-w-0">
          <span class="q-name truncate">{{ row.name }}</span>
          <span v-if="row.connection" class="q-conn">{{ row.connection }}</span>
          <StatusPill v-if="isPaused(row)" status="warn">paused</StatusPill>
        </div>
      </template>
      <template #actions="{ row }">
        <div v-if="pauseTarget(row)" class="flex justify-end">
          <ConfirmAction
            v-if="isPaused(row)"
            label="resume"
            confirm-label="confirm resume"
            @confirm="togglePause(row)"
          />
          <ConfirmAction
            v-else
            label="pause"
            confirm-label="confirm pause"
            @confirm="togglePause(row)"
          />
        </div>
      </template>
    </DataTable>

    <div class="callout" style="margin-top: 24px;">
      Pausing is a soft signal — workers stop popping on their next loop iteration
      (≤ worker sleep, default 3s). Producers can still enqueue. Pause/resume actions fire
      <code>QueuePaused</code> / <code>QueueResumed</code> with <code>actor=dashboard</code>
      and appear in the activity log.
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import StatusPill from '../components/StatusPill.vue';
import ConfirmAction from '../components/ConfirmAction.vue';
import Empty from '../components/Empty.vue';
import Sparkline from '../components/Sparkline.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const supervisors = computed(() => current.value.supervisors ?? []);
const masters = computed(() => current.value.masters ?? []);
const workerMetrics = computed(() => current.value.worker_metrics ?? {});
const workerMetricSeries = computed(() => current.value.worker_metric_series ?? {});

// Per-row sparkline metric toggle: defaults to 'rss', clicking flips to 'cpu'.
// Keyed by PID so each row remembers its own choice across polling refreshes.
const sparkKindByPid = ref({});

function sparkKind(pid) {
  return sparkKindByPid.value[pid] ?? 'rss';
}

function toggleSpark(pid) {
  sparkKindByPid.value = {
    ...sparkKindByPid.value,
    [pid]: sparkKind(pid) === 'rss' ? 'cpu' : 'rss',
  };
}

// Sparkline component expects a normalized 0..1 array. Series points arrive
// as [{ts, value}]; we project to values then divide by the per-series max.
function normalizedPoints(pid) {
  const series = workerMetricSeries.value?.[pid];
  if (! series) return [];

  const points = series[sparkKind(pid)] || [];
  if (points.length === 0) return [];

  const values = points.map((p) => Number(p.value) || 0);
  const max = Math.max(...values);
  if (max <= 0) return values.map(() => 0);
  return values.map((v) => v / max);
}

// Workers list: one row per metrics entry, sorted by supervisor then PID for
// stable rendering across polls.
const workers = computed(() => {
  return Object.values(workerMetrics.value)
    .filter((m) => m && m.pid != null)
    .slice()
    .sort((a, b) => {
      const supA = String(a.supervisor ?? '');
      const supB = String(b.supervisor ?? '');
      if (supA !== supB) return supA.localeCompare(supB);
      return Number(a.pid) - Number(b.pid);
    });
});

// Format helpers. Inline (no composable) — only used by this page.
function formatBytes(n) {
  if (n == null || Number.isNaN(Number(n))) return '—';
  const mb = Number(n) / (1024 * 1024);
  return `${mb.toFixed(1)} MB`;
}

function formatCpu(n) {
  if (n == null || n === '' || Number.isNaN(Number(n))) return '—';
  return `${Number(n).toFixed(1)}%`;
}

async function pause(name) {
  try {
    await axios.post(`/sunset/supervisors/${encodeURIComponent(name)}/pause`);
    router.reload({ only: ['supervisors', 'masters'] });
  } catch (e) {
    console.error('Pause failed:', e);
  }
}

async function resume(name) {
  try {
    await axios.post(`/sunset/supervisors/${encodeURIComponent(name)}/resume`);
    router.reload({ only: ['supervisors', 'masters'] });
  } catch (e) {
    console.error('Resume failed:', e);
  }
}

function statusKind(s) {
  const v = String(s ?? '').toLowerCase();
  if (v === 'paused') return 'warn';
  if (v === 'running' || v === 'continuing') return 'ok';
  if (v === 'terminating' || v === 'terminated') return 'failed';
  return 'info';
}

function queueLabel(s) {
  if (Array.isArray(s.queues)) return s.queues.join(', ');
  if (typeof s.queues === 'string') return s.queues;
  if (typeof s.queue === 'string') return s.queue;
  return '';
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-base font-bold">Supervisors</h1>

    <section v-if="masters.length > 0">
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Masters ({{ masters.length }})</h2>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="m in masters"
          :key="m.name"
          class="border border-sunset-border rounded p-3 bg-sunset-card text-xs"
        >
          <div class="flex items-center justify-between mb-1">
            <span class="font-bold">{{ m.name }}</span>
            <StatusPill :status="statusKind(m.status)">{{ m.status }}</StatusPill>
          </div>
          <div class="text-sunset-muted">PID {{ m.pid ?? '—' }}</div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">
        Supervisors ({{ supervisors.length }})
      </h2>
      <Empty v-if="supervisors.length === 0" message="No supervisors running." />
      <div v-else class="grid gap-3 md:grid-cols-2">
        <div
          v-for="s in supervisors"
          :key="s.name"
          class="border border-sunset-border rounded p-3 bg-sunset-card"
        >
          <div class="flex items-center justify-between mb-2">
            <span class="font-bold text-sm">{{ s.name }}</span>
            <StatusPill :status="statusKind(s.status)">{{ s.status }}</StatusPill>
          </div>
          <div class="text-xs text-sunset-muted mb-3 space-y-1">
            <div>{{ s.processes ?? 0 }} processes</div>
            <div v-if="queueLabel(s)" class="truncate">{{ queueLabel(s) }}</div>
          </div>
          <div class="flex gap-2">
            <ConfirmAction
              v-if="String(s.status).toLowerCase() !== 'paused'"
              label="pause"
              confirm-label="confirm pause"
              @confirm="pause(s.name)"
            />
            <ConfirmAction
              v-else
              label="resume"
              confirm-label="confirm resume"
              @confirm="resume(s.name)"
            />
          </div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">
        Workers ({{ workers.length }})
      </h2>
      <Empty v-if="workers.length === 0" message="No worker telemetry reported yet." />
      <div
        v-else
        class="border border-sunset-border rounded divide-y divide-sunset-border bg-sunset-card"
      >
        <div
          class="px-3 py-2 grid grid-cols-[1fr_90px_70px_70px_140px] items-center gap-3 text-[10px] uppercase text-sunset-muted"
        >
          <div>Worker</div>
          <div class="text-right">PID</div>
          <div class="text-right">RSS</div>
          <div class="text-right">CPU%</div>
          <div class="text-right">Trend</div>
        </div>
        <div
          v-for="w in workers"
          :key="w.pid"
          class="px-3 py-2 grid grid-cols-[1fr_90px_70px_70px_140px] items-center gap-3 text-xs cursor-pointer hover:bg-sunset-border/30"
          :title="`click to toggle sparkline metric (currently ${sparkKind(w.pid).toUpperCase()})`"
          @click="toggleSpark(w.pid)"
        >
          <div class="truncate">
            <div class="font-bold">{{ w.supervisor ?? '—' }}</div>
            <div v-if="Array.isArray(w.queues) && w.queues.length" class="text-sunset-muted truncate">
              {{ w.queues.join(', ') }}
            </div>
          </div>
          <div class="text-right tabular-nums text-sunset-muted">{{ w.pid ?? '—' }}</div>
          <div class="text-right tabular-nums">{{ formatBytes(w.rss_bytes) }}</div>
          <div class="text-right tabular-nums">{{ formatCpu(w.cpu_pct) }}</div>
          <div class="text-sunset-accent text-right">
            <Sparkline :points="normalizedPoints(w.pid)" />
            <div class="text-[10px] text-sunset-muted uppercase mt-0.5">
              {{ sparkKind(w.pid) }}
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import ConfirmAction from '../components/ConfirmAction.vue';
import Empty from '../components/Empty.vue';
import Sparkline from '../components/Sparkline.vue';
import { useToasts } from '../composables/useToasts.js';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const supervisors = computed(() => current.value.supervisors ?? []);
const masters = computed(() => current.value.masters ?? []);
const workerMetrics = computed(() => current.value.worker_metrics ?? {});
const workerMetricSeries = computed(() => current.value.worker_metric_series ?? {});

const { push } = useToasts();
const sparkKindByPid = ref({});

function sparkKind(pid) { return sparkKindByPid.value[pid] ?? 'rss'; }
function toggleSpark(pid) {
  sparkKindByPid.value = {
    ...sparkKindByPid.value,
    [pid]: sparkKind(pid) === 'rss' ? 'cpu' : 'rss',
  };
}
function normalizedPoints(pid) {
  const series = workerMetricSeries.value?.[pid];
  if (!series) return [];
  const points = series[sparkKind(pid)] || [];
  if (points.length === 0) return [];
  const values = points.map((p) => Number(p.value) || 0);
  const max = Math.max(...values);
  if (max <= 0) return values.map(() => 0);
  return values.map((v) => v / max);
}

const workers = computed(() => Object.values(workerMetrics.value)
  .filter((m) => m && m.pid != null)
  .slice()
  .sort((a, b) => {
    const supA = String(a.supervisor ?? '');
    const supB = String(b.supervisor ?? '');
    if (supA !== supB) return supA.localeCompare(supB);
    return Number(a.pid) - Number(b.pid);
  }));

function formatBytes(n) {
  if (n == null || Number.isNaN(Number(n))) return '—';
  const mb = Number(n) / (1024 * 1024);
  return `${mb.toFixed(1)} MB`;
}
function formatCpu(n) {
  if (n == null || n === '' || Number.isNaN(Number(n))) return '—';
  return `${Number(n).toFixed(1)}%`;
}

const supervisorRows = computed(() => supervisors.value.map((s) => ({
  ...s,
  connection: s.options?.connection ?? s.connection ?? '—',
  queues_label: Array.isArray(s.queues) ? s.queues.join(', ')
               : typeof s.queues === 'string' ? s.queues
               : (s.options?.queue ?? ''),
  procs_label: `${s.processes ?? 0} / ${s.options?.maxProcesses ?? '—'}`,
})));

const workerRows = computed(() => workers.value.map((w) => ({
  ...w,
  pid: w.pid,
  rss_bytes: w.rss_bytes,
  cpu_pct: w.cpu_pct,
  uptime: w.started_at ? Math.max(0, Math.floor(Date.now() / 1000 - w.started_at)) : 0,
})));
function fmtUptime(secs) {
  if (!secs) return '—';
  if (secs < 60) return `${secs}s`;
  if (secs < 3600) return `${Math.floor(secs / 60)}m`;
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ${Math.floor((secs % 3600) / 60)}m`;
  return `${Math.floor(secs / 86400)}d ${Math.floor((secs % 86400) / 3600)}h`;
}

const leakingWorker = computed(() =>
  workers.value.find((w) => Number(w.rss_bytes ?? 0) > 100 * 1024 * 1024),
);

async function pauseSupervisor(name) {
  try {
    await axios.post(`/sunset/supervisors/${encodeURIComponent(name)}/pause`);
    push({ kind: 'ok', title: 'Supervisor paused.', undo: true });
    router.reload({ only: ['supervisors', 'masters'] });
  } catch (e) {
    push({ kind: 'err', title: 'Pause failed.', sub: e?.message });
  }
}
async function resumeSupervisor(name) {
  try {
    await axios.post(`/sunset/supervisors/${encodeURIComponent(name)}/resume`);
    push({ kind: 'ok', title: 'Supervisor resumed.' });
    router.reload({ only: ['supervisors', 'masters'] });
  } catch (e) {
    push({ kind: 'err', title: 'Resume failed.', sub: e?.message });
  }
}

// v2.3.0: live worker scaling. Each +/− click posts a fresh absolute target
// (current ± 1), not a delta — the server clamps to [1, 256] and the
// supervisor's next loop tick brings worker count to match. We intentionally
// don't await router.reload here: the supervisor needs ~1s to drain the
// command queue, so polling refresh picks up the new count naturally.
const scaling = ref({}); // name → bool, dim buttons while a request is in flight

async function scaleSupervisor(name, delta, current, ceiling) {
  if (scaling.value[name]) return;
  const target = (Number(current) || 0) + delta;
  if (target < 1) return;
  if (Number.isFinite(ceiling) && target > ceiling) return;

  scaling.value = { ...scaling.value, [name]: true };
  try {
    await axios.post(`/sunset/supervisors/${encodeURIComponent(name)}/scale`, {
      processes: target,
    });
    push({ kind: 'ok', title: `Scaled supervisor ${name} to ${target} worker${target === 1 ? '' : 's'}.` });
  } catch (e) {
    push({ kind: 'err', title: 'Scale failed.', sub: e?.message });
  } finally {
    scaling.value = { ...scaling.value, [name]: false };
  }
}
function statusKind(s) {
  const v = String(s ?? '').toLowerCase();
  if (v === 'paused') return 'warn';
  if (v === 'running' || v === 'continuing') return 'ok';
  if (v === 'terminating' || v === 'terminated') return 'err';
  return 'info';
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Supervisors</h1>
      <span class="page-sub">
        {{ masters.length }} master{{ masters.length === 1 ? '' : 's' }} ·
        {{ supervisors.length }} supervisor{{ supervisors.length === 1 ? '' : 's' }} ·
        {{ workers.length }} worker{{ workers.length === 1 ? '' : 's' }}
      </span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Process tree</h2>
        <span class="meta">{{ supervisors.length }} supervisor{{ supervisors.length === 1 ? '' : 's' }}</span>
      </div>
      <Empty v-if="supervisors.length === 0" message="No supervisors running." />
      <DataTable
        v-else
        :columns="[
          { key: 'name', label: 'Supervisor', width: '1.4fr' },
          { key: 'status', label: 'Status', width: '130px' },
          { key: 'procs_label', label: 'Workers', width: '120px', align: 'right' },
          { key: 'connection', label: 'Connection', width: '130px' },
          { key: 'queues_label', label: 'Queues', width: '1fr' },
          { key: 'actions', label: '', width: '230px' },
        ]"
        :rows="supervisorRows"
        :selectable="false"
      >
        <template #name="{ row }">
          <span class="q-name">{{ row.name }}</span>
        </template>
        <template #status="{ row }">
          <StatusPill :status="statusKind(row.status)">{{ row.status }}</StatusPill>
        </template>
        <template #connection="{ row }">
          <span class="pill neutral">{{ row.connection }}</span>
        </template>
        <template #actions="{ row }">
          <div class="flex justify-end items-center gap-1" @click.stop>
            <!-- v2.3.0: live worker scaling. No ConfirmAction wrapper here —
                 these are cheap and reversible (the next click undoes them);
                 confirmation friction would just slow operators down. -->
            <button
              class="btn ghost sm"
              type="button"
              :disabled="(row.processes ?? 0) <= 1 || scaling[row.name]"
              :title="(row.processes ?? 0) <= 1
                ? 'Pause the supervisor instead of scaling to 0 workers'
                : 'Remove one worker without restarting the supervisor'"
              @click="scaleSupervisor(row.name, -1, row.processes, row.options?.maxProcesses)"
            >−</button>
            <button
              class="btn ghost sm"
              type="button"
              :disabled="Number.isFinite(row.options?.maxProcesses)
                && (row.processes ?? 0) >= (row.options?.maxProcesses ?? Infinity)
                || scaling[row.name]"
              :title="Number.isFinite(row.options?.maxProcesses)
                && (row.processes ?? 0) >= (row.options?.maxProcesses ?? Infinity)
                  ? `At configured ceiling (${row.options?.maxProcesses})`
                  : 'Add one worker without restarting the supervisor'"
              @click="scaleSupervisor(row.name, 1, row.processes, row.options?.maxProcesses)"
            >+</button>
            <ConfirmAction
              v-if="String(row.status).toLowerCase() !== 'paused'"
              label="pause"
              confirm-label="confirm pause"
              @confirm="pauseSupervisor(row.name)"
            />
            <ConfirmAction
              v-else
              label="resume"
              confirm-label="confirm resume"
              @confirm="resumeSupervisor(row.name)"
            />
          </div>
        </template>
      </DataTable>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Workers</h2>
        <span class="meta">live telemetry · click row to toggle RSS ↔ CPU</span>
      </div>
      <Empty v-if="workerRows.length === 0" message="No worker telemetry reported yet." />
      <DataTable
        v-else
        :columns="[
          { key: 'supervisor', label: 'Worker', width: '1.5fr' },
          { key: 'pid', label: 'PID', width: '90px', align: 'right' },
          { key: 'rss_bytes', label: 'RSS', width: '110px', align: 'right', sortable: 'num' },
          { key: 'cpu_pct', label: 'CPU %', width: '100px', align: 'right', sortable: 'num' },
          { key: 'uptime', label: 'Uptime', width: '110px', align: 'right' },
          { key: 'trend', label: 'Trend', width: '160px' },
        ]"
        :rows="workerRows"
        :clickable="true"
        :selectable="false"
        @row-click="(r) => toggleSpark(r.pid)"
      >
        <template #supervisor="{ row }">
          <div>
            <span class="q-name">{{ row.supervisor ?? '—' }}</span>
            <StatusPill v-if="Number(row.rss_bytes ?? 0) > 100 * 1024 * 1024" status="warn" style="margin-left: 8px;">High mem</StatusPill>
            <div v-if="Array.isArray(row.queues) && row.queues.length" style="font-size: 11px; color: rgb(var(--muted)); margin-top: 2px; font-family: 'Geist Mono', monospace;">
              {{ row.queues.join(', ') }}
            </div>
          </div>
        </template>
        <template #rss_bytes="{ row }">
          <span :style="Number(row.rss_bytes ?? 0) > 100 * 1024 * 1024 ? 'color: rgb(var(--red))' : ''">
            {{ formatBytes(row.rss_bytes) }}
          </span>
        </template>
        <template #cpu_pct="{ row }">
          <span>{{ formatCpu(row.cpu_pct) }}</span>
        </template>
        <template #uptime="{ row }">{{ fmtUptime(row.uptime) }}</template>
        <template #trend="{ row }">
          <Sparkline :points="normalizedPoints(row.pid)" :color="sparkKind(row.pid) === 'cpu' ? 'amber' : 'violet'" :width="100" :height="18" />
          <div style="font-size: 9.5px; color: rgb(var(--dim)); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px;">
            {{ sparkKind(row.pid) }}
          </div>
        </template>
      </DataTable>
    </div>

    <div v-if="leakingWorker" class="banner-warn" style="border-radius: 10px; padding: 14px 16px; margin-top: 20px; display: flex; align-items: flex-start; gap: 12px;">
      <svg style="width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: rgb(var(--amber));"><use href="#i-alert"/></svg>
      <div style="font-size: 13px; color: rgb(var(--text-2));">
        Worker <code style="font-family: 'Geist Mono', monospace; padding: 1px 6px; background: rgb(var(--bg-3)); border-radius: 4px; color: rgb(var(--violet));">{{ leakingWorker.pid }}</code> RSS climbing —
        currently {{ formatBytes(leakingWorker.rss_bytes) }}. Will auto-restart at the memory limit.
      </div>
    </div>
  </div>
</template>

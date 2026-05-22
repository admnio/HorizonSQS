<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import Empty from '../components/Empty.vue';
import StatusPill from '../components/StatusPill.vue';
import Sparkline from '../components/Sparkline.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const workload = computed(() => current.value.workload ?? []);
const supervisors = computed(() => current.value.supervisors ?? []);
const masters = computed(() => current.value.masters ?? []);
const recent = computed(() => current.value.recent ?? []);

const totalQueueDepth = computed(() =>
  workload.value.reduce((s, q) => s + Number(q.length ?? 0), 0),
);
const activeSupervisors = computed(() => supervisors.value.length);
const totalProcesses = computed(() =>
  supervisors.value.reduce((s, sup) => {
    const p = sup.processes ?? {};
    return s + Object.values(p).reduce((a, n) => a + Number(n ?? 0), 0);
  }, 0),
);

// Hero-stat aggregates. The OverviewController fills these in on every
// render and poll tick. The `??` fallbacks cover the (defensive) case where
// a stale poll response is still in-flight when the page first mounts.
const throughputPerMin = computed(() => current.value.throughput_per_min ?? '—');
const failureRatePct = computed(() => current.value.failure_rate_pct ?? '—');
const failuresLastHour = computed(() => current.value.failures_last_hour ?? 0);

// TODO(v2-wire-data): per-stat sparklines need recent-series data. Placeholder
// arrays so the Sparkline component has something to render.
const queueDepthSeries = [0.4, 0.5, 0.55, 0.5, 0.6, 0.65, 0.7, 0.72, 0.78, 0.82, 0.86];
const throughputSeries = [0.2, 0.3, 0.4, 0.45, 0.5, 0.55, 0.7, 0.78, 0.85, 0.92];
const failureSeries = [0.15, 0.18, 0.12, 0.22, 0.16, 0.2, 0.1, 0.18, 0.08, 0.05];

const recentLog = computed(() => (recent.value || []).slice(0, 5));

function eventPillStatus(t) {
  if (t === 'job_failed' || t === 'unable_to_launch_process') return 'err';
  if (t === 'job_rate_limited' || t === 'long_wait_detected' || t === 'worker_process_restarting') return 'warn';
  if (t === 'job_completed' || t === 'master_supervisor_deployed') return 'ok';
  return 'info';
}
function eventTitle(t) {
  return (t || '').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}
function eventSummary(e) {
  const p = e.payload ?? {};
  switch (e.type) {
    case 'job_failed':
      return `${p.job_class ?? p.job_id ?? 'unknown'} on ${p.queue}: ${p.exception_class ?? 'exception'}`;
    case 'job_completed':
      return `${p.job_class ?? p.job_id} on ${p.queue}${p.duration_ms != null ? ` (${p.duration_ms}ms)` : ''}`;
    case 'job_rate_limited':
      return `${p.job_class ?? p.job_id} on ${p.queue} hit ${p.limit_name}`;
    case 'queue_paused':
      return `${p.connection}:${p.queue} paused${p.actor ? ` by ${p.actor}` : ''}`;
    case 'master_supervisor_deployed':
      return `master ${p.master_name ?? ''} deployed`;
    case 'long_wait_detected':
      return `${p.connection}:${p.queue} idle ${p.seconds}s`;
    default:
      return e.type;
  }
}
function fmtTime(sec) {
  return sec ? new Date(sec * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—';
}
function relTime(sec) {
  if (!sec) return '';
  const diff = Math.max(0, Math.floor(Date.now() / 1000 - sec));
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  return `${Math.floor(diff / 3600)}h ago`;
}

function goWorkload() { router.visit('/sunset/workload'); }
function goActivity() { router.visit('/sunset/activity'); }
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Overview</h1>
      <span class="page-sub">real-time queue observability</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg>Refresh</button>
        <button class="btn primary" @click="router.visit('/sunset/rate-limits')">
          <svg><use href="#i-plus"/></svg>New limit
        </button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-label" v-tooltip="'Total jobs waiting across all queues, all transports'">
          <svg><use href="#i-list"/></svg>Queue depth
        </div>
        <div class="stat-value tabular-nums">{{ totalQueueDepth.toLocaleString() }}</div>
        <div class="stat-delta">across {{ workload.length }} queues</div>
        <Sparkline :points="queueDepthSeries" color="violet" area :width="92" :height="28" class="stat-spark" />
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Active supervisor processes managing workers'">
          <svg><use href="#i-server"/></svg>Supervisors
        </div>
        <div class="stat-value">
          {{ activeSupervisors }}
          <span class="unit">/ {{ masters.length || 1 }} masters</span>
        </div>
        <div class="stat-delta">{{ totalProcesses }} worker processes</div>
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Jobs completed (or failed) per minute, rolling average'">
          <svg><use href="#i-zap"/></svg>Throughput
        </div>
        <div class="stat-value">
          {{ throughputPerMin }}
          <span class="unit">jobs/min</span>
        </div>
        <div class="stat-delta">live</div>
        <Sparkline :points="throughputSeries" color="green" :width="92" :height="28" class="stat-spark" />
      </div>
      <div class="stat">
        <div class="stat-label" v-tooltip="'Percent of jobs that failed in the last hour'">
          <svg><use href="#i-alert"/></svg>Failure rate
        </div>
        <div class="stat-value red">{{ failureRatePct }}<span class="unit" style="color: rgb(var(--red)); opacity: 0.7;">%</span></div>
        <div class="stat-delta">{{ failuresLastHour }} in last hour</div>
        <Sparkline :points="failureSeries" color="red" :width="92" :height="28" class="stat-spark" />
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Workload</h2>
        <span class="meta">{{ workload.length }} queues</span>
        <a class="link" @click="goWorkload">
          View all
          <svg style="width:12px;height:12px"><use href="#i-arrow-right"/></svg>
        </a>
      </div>
      <Empty v-if="workload.length === 0" message="No queues active." />
      <DataTable
        v-else
        :columns="[
          { key: 'name', label: 'Queue', width: '1fr' },
          { key: 'length', label: 'Length', width: '110px', align: 'right' },
          { key: 'processes', label: 'Procs', width: '90px', align: 'right' },
          { key: 'wait', label: 'Wait (s)', width: '100px', align: 'right' },
        ]"
        :rows="workload"
        :selectable="false"
      />
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Recent activity</h2>
        <span class="meta">live</span>
        <a class="link" @click="goActivity">
          Open Activity
          <svg style="width:12px;height:12px"><use href="#i-arrow-right"/></svg>
        </a>
      </div>
      <Empty v-if="recentLog.length === 0" message="No recent events." />
      <div v-else class="log">
        <div v-for="e in recentLog" :key="e.id" class="log-entry">
          <span class="ts">
            {{ fmtTime(e.occurred_at) }}
            <span class="rel">{{ relTime(e.occurred_at) }}</span>
          </span>
          <span><StatusPill :status="eventPillStatus(e.type)">{{ eventTitle(e.type) }}</StatusPill></span>
          <span class="body">{{ eventSummary(e) }}</span>
          <span class="id">{{ e.id }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

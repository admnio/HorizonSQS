<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';
import StatusPill from '../components/StatusPill.vue';
import FilterBar from '../components/FilterBar.vue';
import SearchInput from '../components/SearchInput.vue';
import RangeGroup from '../components/RangeGroup.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const enabled = computed(() => current.value.enabled ?? true);
const pageUrl = computed(() => current.value.page_url ?? '/sunset/activity/page');

const MAX_CLIENT_BUFFER = 1000;

const olderEvents = ref([]);
const filter = ref('errors');
const range = ref('1h');
const search = ref('');
const expanded = ref(new Set());
const paused = ref(false);

const events = computed(() => {
  const polled = current.value.events ?? [];
  const byId = new Map();
  for (const e of polled) byId.set(e.id, e);
  for (const e of olderEvents.value) if (!byId.has(e.id)) byId.set(e.id, e);
  return [...byId.values()].sort((a, b) => b.id - a.id).slice(0, MAX_CLIENT_BUFFER);
});

const FILTERS = {
  all:        () => true,
  errors:     (e) => ['job_failed', 'job_rate_limited', 'unable_to_launch_process'].includes(e.type),
  lifecycle:  (e) => ['job_queued', 'job_completed'].includes(e.type),
  supervisor: (e) => ['worker_process_restarting', 'master_supervisor_deployed', 'long_wait_detected', 'queue_paused', 'queue_resumed'].includes(e.type),
};

const visibleEvents = computed(() => {
  const q = search.value.toLowerCase().trim();
  return events.value
    .filter(FILTERS[filter.value] ?? FILTERS.all)
    .filter((e) => !q || (
      e.type.includes(q) ||
      JSON.stringify(e.payload ?? {}).toLowerCase().includes(q)
    ));
});

function eventPillStatus(t) {
  if (t === 'job_failed' || t === 'unable_to_launch_process') return 'err';
  if (t === 'job_rate_limited' || t === 'long_wait_detected' || t === 'worker_process_restarting') return 'warn';
  if (t === 'job_completed' || t === 'master_supervisor_deployed') return 'ok';
  if (t === 'queue_paused' || t === 'queue_resumed') return 'info';
  return 'info';
}
function eventTitle(t) {
  return (t || '').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}
function summary(e) {
  const p = e.payload ?? {};
  switch (e.type) {
    case 'job_failed':
      return `${p.job_class ?? p.job_id ?? 'unknown job'} on ${p.queue}: ${p.exception_class ?? 'exception'}${p.exception_message ? ' — ' + p.exception_message : ''}`;
    case 'job_completed':
      return `${p.job_class ?? p.job_id} on ${p.queue}${p.duration_ms != null ? ` (${p.duration_ms}ms)` : ''}`;
    case 'job_rate_limited':
      return `${p.job_class ?? p.job_id} on ${p.queue} hit ${p.limit_name} (${p.strategy}, retry after ${p.retry_after}s)`;
    case 'job_queued':
      return `${p.job_class ?? p.job_id} → ${p.connection}:${p.queue}`;
    case 'worker_process_restarting':
      return `worker pid ${p.pid ?? '?'} restarting`;
    case 'unable_to_launch_process':
      return `failed to launch worker pid ${p.pid ?? '?'}: ${p.command ?? '(no command)'}`;
    case 'long_wait_detected':
      return `${p.connection}:${p.queue} idle ${p.seconds}s`;
    case 'master_supervisor_deployed':
      return `master ${p.master_name} deployed`;
    case 'queue_paused':
      return `${p.connection}:${p.queue} paused${p.actor ? ` by ${p.actor}` : ''}`;
    case 'queue_resumed':
      return `${p.connection}:${p.queue} resumed${p.actor ? ` by ${p.actor}` : ''}`;
    default:
      return e.type;
  }
}

function toggleExpand(id) {
  if (expanded.value.has(id)) expanded.value.delete(id);
  else expanded.value.add(id);
}

async function loadOlder() {
  const oldest = events.value[events.value.length - 1];
  if (!oldest) return;
  const res = await fetch(`${pageUrl.value}?before_id=${oldest.id}`, { headers: { 'Accept': 'application/json' } });
  if (!res.ok) return;
  const json = await res.json();
  olderEvents.value.push(...(json.events ?? []));
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
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Activity</h1>
      <span class="page-sub">live event stream</span>
      <div class="page-actions">
        <button class="btn" @click="paused = !paused">
          <svg><use :href="paused ? '#i-play' : '#i-pause'"/></svg>
          {{ paused ? 'Resume' : 'Pause' }}
        </button>
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div v-if="!enabled" class="banner-warn rounded p-3 text-xs" style="border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;">
      Activity recording is disabled. Set <code>SUNSET_ACTIVITY_ENABLED=true</code> to enable.
    </div>

    <FilterBar :count="visibleEvents.length" count-label="entries">
      <template #search>
        <SearchInput v-model="search" placeholder="Filter by class, queue, exception…" />
      </template>
      <template #range>
        <RangeGroup v-model="range" :options="['10m','1h','6h','24h']" />
      </template>
    </FilterBar>

    <div class="filters">
      <button class="filter" :class="{ active: filter === 'errors' }" @click="filter = 'errors'">Errors</button>
      <button class="filter" :class="{ active: filter === 'all' }" @click="filter = 'all'">All</button>
      <button class="filter" :class="{ active: filter === 'lifecycle' }" @click="filter = 'lifecycle'">Lifecycle</button>
      <button class="filter" :class="{ active: filter === 'supervisor' }" @click="filter = 'supervisor'">Supervisor</button>
      <span class="meta">
        <span class="pulse"></span> live · auto-refresh 3s
      </span>
    </div>

    <Empty v-if="visibleEvents.length === 0" message="No events match the current filter." />

    <div v-else class="log">
      <div v-for="e in visibleEvents" :key="e.id" class="log-entry" @click="toggleExpand(e.id)">
        <span class="ts">
          {{ fmtTime(e.occurred_at) }}
          <span class="rel">{{ relTime(e.occurred_at) }}</span>
        </span>
        <span>
          <StatusPill :status="eventPillStatus(e.type)">{{ eventTitle(e.type) }}</StatusPill>
        </span>
        <span class="body">
          {{ summary(e) }}
          <pre v-if="expanded.has(e.id)" style="margin-top: 8px; padding: 10px 12px; background: rgb(var(--bg-2)); border: 1px solid rgb(var(--border-soft)); border-radius: 6px; font-size: 11.5px; font-family: 'Geist Mono', monospace; line-height: 1.55; white-space: pre-wrap; color: rgb(var(--text-2));">{{ JSON.stringify(e.payload, null, 2) }}</pre>
        </span>
        <span class="id">{{ e.id }}</span>
      </div>
    </div>

    <div class="text-center" style="margin-top: 20px;">
      <button class="btn" @click="loadOlder">Load older</button>
    </div>

    <div class="callout">
      Public events <code>QueuePaused</code>, <code>QueueResumed</code>, and <code>ActivityRecorded</code>
      let you forward this stream to Slack, your audit log, or Datadog — subscribe in any service provider.
    </div>
  </div>
</template>

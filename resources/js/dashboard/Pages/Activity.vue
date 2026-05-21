<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const enabled = computed(() => current.value.enabled ?? true);
const pageUrl = computed(() => current.value.page_url ?? '/sunset/activity/page');

const MAX_CLIENT_BUFFER = 1000;

// Polled events from the server are descending by id, but the user has also
// "loaded older" entries below. We track those separately and concatenate.
// The polled set always wins for the head of the list — anything newer than
// the highest id in `olderEvents` lives in the polled list automatically.
const olderEvents = ref([]);          // events fetched via "Load older"
const filter = ref('errors');         // 'all' | 'errors' | 'lifecycle' | 'supervisor'
const expanded = ref(new Set());

const events = computed(() => {
  const polled = current.value.events ?? [];
  // De-dupe: olderEvents may overlap polled if the buffer churned during a
  // load-older fetch. Use a Map keyed by id to drop duplicates, preferring
  // the polled (newer cache) over the older copy.
  const byId = new Map();
  for (const e of polled) byId.set(e.id, e);
  for (const e of olderEvents.value) if (! byId.has(e.id)) byId.set(e.id, e);
  return [...byId.values()]
    .sort((a, b) => b.id - a.id)
    .slice(0, MAX_CLIENT_BUFFER);
});

const FILTERS = {
  all:        () => true,
  errors:     (e) => ['job_failed', 'job_rate_limited', 'unable_to_launch_process'].includes(e.type),
  lifecycle:  (e) => ['job_queued', 'job_completed'].includes(e.type),
  supervisor: (e) => ['worker_process_restarting', 'master_supervisor_deployed', 'long_wait_detected', 'queue_paused', 'queue_resumed'].includes(e.type),
};

const visibleEvents = computed(() => events.value.filter(FILTERS[filter.value]));

const pillClass = (type) => {
  if (type === 'job_failed' || type === 'unable_to_launch_process') return 'pill-error';
  if (type === 'job_rate_limited' || type === 'long_wait_detected' || type === 'worker_process_restarting') return 'pill-warn';
  if (type === 'job_completed' || type === 'master_supervisor_deployed') return 'pill-ok';
  if (type === 'queue_paused' || type === 'queue_resumed') return 'pill-info';
  return 'pill-info';
};

const summary = (event) => {
  const p = event.payload ?? {};
  switch (event.type) {
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
      return event.type;
  }
};

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

const formatTs = (sec) => new Date(sec * 1000).toLocaleTimeString();
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Activity</h1>

    <div v-if="!enabled" class="banner-warn rounded p-3 text-xs">
      Activity recording is disabled. Set <code>SUNSET_ACTIVITY_ENABLED=true</code> to enable.
    </div>

    <div class="flex gap-1 text-xs">
      <button v-for="f in ['errors', 'all', 'lifecycle', 'supervisor']" :key="f"
        @click="filter = f"
        :class="['px-2 py-1 rounded border', filter === f ? 'bg-slate-200 dark:bg-slate-700 font-bold' : '']">
        {{ f }}
      </button>
    </div>

    <Empty v-if="visibleEvents.length === 0" message="No events match the current filter." />

    <ul v-else class="space-y-1">
      <li v-for="e in visibleEvents" :key="e.id"
        @click="toggleExpand(e.id)"
        class="border rounded p-2 text-xs font-mono cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-900">
        <div class="flex items-baseline gap-2">
          <span class="text-slate-500">{{ formatTs(e.occurred_at) }}</span>
          <span :class="['inline-block px-1.5', pillClass(e.type)]">{{ e.type }}</span>
          <span class="flex-1 truncate">{{ summary(e) }}</span>
          <span class="text-slate-400">#{{ e.id }}</span>
        </div>
        <pre v-if="expanded.has(e.id)" class="mt-2 whitespace-pre-wrap text-xs bg-slate-50 dark:bg-slate-900 p-2 rounded">{{ JSON.stringify(e.payload, null, 2) }}</pre>
      </li>
    </ul>

    <div class="text-center">
      <button @click="loadOlder" class="text-xs px-3 py-1 border rounded">Load older</button>
    </div>
  </div>
</template>

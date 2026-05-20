<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useActivityStream } from '../composables/useActivityStream.js';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const enabled = computed(() => initial.enabled ?? true);
const streamUrl = computed(() => initial.stream_url ?? '/sunset/activity/stream');
const pageUrl = computed(() => initial.page_url ?? '/sunset/activity/page');

const MAX_CLIENT_BUFFER = 1000;

const events = ref([...(initial.events ?? [])]);           // descending by id
const paused = ref(false);
const pausedQueue = ref([]);                                // events received while paused
const filter = ref('errors');                               // 'all' | 'errors' | 'lifecycle' | 'supervisor'
const expanded = ref(new Set());                            // set of event ids currently expanded
const stream = useActivityStream();

const FILTERS = {
  all:        () => true,
  errors:     (e) => ['job_failed', 'job_rate_limited', 'unable_to_launch_process'].includes(e.type),
  lifecycle:  (e) => ['job_queued', 'job_completed'].includes(e.type),
  supervisor: (e) => ['worker_process_restarting', 'master_supervisor_deployed', 'long_wait_detected'].includes(e.type),
};

const visibleEvents = computed(() => events.value.filter(FILTERS[filter.value]));

const pillClass = (type) => {
  if (type === 'job_failed' || type === 'unable_to_launch_process') return 'pill-error';
  if (type === 'job_rate_limited' || type === 'long_wait_detected' || type === 'worker_process_restarting') return 'pill-warn';
  if (type === 'job_completed' || type === 'master_supervisor_deployed') return 'pill-ok';
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
    default:
      return event.type;
  }
};

function handleIncoming(event) {
  if (paused.value) {
    pausedQueue.value.push(event);
    return;
  }
  events.value.unshift(event);
  if (events.value.length > MAX_CLIENT_BUFFER) {
    events.value = events.value.slice(0, MAX_CLIENT_BUFFER);
  }
}

function togglePause() {
  paused.value = !paused.value;
  if (!paused.value && pausedQueue.value.length) {
    // Drain in chronological order (oldest first) so they end up newest-first when unshifted.
    pausedQueue.value.reverse().forEach((e) => handleIncoming(e));
    pausedQueue.value = [];
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
  events.value.push(...(json.events ?? []));
}

onMounted(() => {
  if (enabled.value) stream.start(streamUrl.value, handleIncoming);
});

onUnmounted(() => stream.stop());

const formatTs = (sec) => {
  const d = new Date(sec * 1000);
  return d.toLocaleTimeString();
};
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <h1 class="text-base font-bold">Activity</h1>
      <div class="flex items-center gap-2 text-xs">
        <span v-if="stream.isOpen.value" class="pill-ok">live</span>
        <span v-else-if="enabled" class="pill-warn">reconnecting</span>
        <button @click="togglePause" class="px-2 py-1 border rounded">
          {{ paused ? `Resume${pausedQueue.length ? ` (${pausedQueue.length})` : ''}` : 'Pause' }}
        </button>
      </div>
    </div>

    <div v-if="!enabled" class="banner-warn rounded p-3 text-xs">
      Activity streaming is disabled. Set <code>SUNSET_ACTIVITY_ENABLED=true</code> to enable.
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

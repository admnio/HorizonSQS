<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import StatusPill from '../components/StatusPill.vue';
import ConfirmAction from '../components/ConfirmAction.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const supervisors = computed(() => current.value.supervisors ?? []);
const masters = computed(() => current.value.masters ?? []);

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
  </div>
</template>

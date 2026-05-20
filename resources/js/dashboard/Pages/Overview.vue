<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const workload = computed(() => current.value.workload ?? []);
const supervisors = computed(() => current.value.supervisors ?? []);
const masters = computed(() => current.value.masters ?? []);
const recent = computed(() => current.value.recent ?? []);

const totalQueueDepth = computed(() =>
  workload.value.reduce((s, q) => s + Number(q.length ?? 0), 0)
);
const activeSupervisors = computed(() => supervisors.value.length);
const activeMasters = computed(() => masters.value.length);
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-base font-bold">Overview</h1>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="border border-sunset-border rounded p-3 bg-sunset-card">
        <div class="text-[10px] uppercase text-sunset-muted">Queue depth</div>
        <div class="text-2xl font-bold tabular-nums">{{ totalQueueDepth }}</div>
      </div>
      <div class="border border-sunset-border rounded p-3 bg-sunset-card">
        <div class="text-[10px] uppercase text-sunset-muted">Supervisors</div>
        <div class="text-2xl font-bold tabular-nums">{{ activeSupervisors }}</div>
      </div>
      <div class="border border-sunset-border rounded p-3 bg-sunset-card">
        <div class="text-[10px] uppercase text-sunset-muted">Masters</div>
        <div class="text-2xl font-bold tabular-nums">{{ activeMasters }}</div>
      </div>
      <div class="border border-sunset-border rounded p-3 bg-sunset-card">
        <div class="text-[10px] uppercase text-sunset-muted">Recent jobs</div>
        <div class="text-2xl font-bold tabular-nums">{{ recent.length }}</div>
      </div>
    </div>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Workload</h2>
      <Empty v-if="workload.length === 0" message="No queues active." />
      <DataTable
        v-else
        :columns="[
          { key: 'name', label: 'Queue', width: '1fr' },
          { key: 'length', label: 'Length', width: '90px' },
          { key: 'processes', label: 'Procs', width: '90px' },
          { key: 'wait', label: 'Wait (s)', width: '90px' },
        ]"
        :rows="workload"
        :selectable="false"
      />
    </section>
  </div>
</template>

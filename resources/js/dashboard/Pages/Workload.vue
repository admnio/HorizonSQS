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
const queues = computed(() => current.value.queues ?? []);
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Workload</h1>
    <Empty v-if="queues.length === 0" message="No queues configured." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Queue', width: '1fr' },
        { key: 'length', label: 'Length', width: '100px' },
        { key: 'processes', label: 'Processes', width: '110px' },
        { key: 'wait', label: 'Wait (s)', width: '110px' },
      ]"
      :rows="queues"
      :selectable="false"
    />
  </div>
</template>

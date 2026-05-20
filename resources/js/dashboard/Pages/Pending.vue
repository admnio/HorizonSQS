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
const jobs = computed(() => current.value.jobs ?? []);
const total = computed(() => current.value.total ?? jobs.value.length);
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">
      Pending jobs
      <span class="text-sunset-muted text-xs ml-1">{{ total }}</span>
    </h1>
    <Empty v-if="jobs.length === 0" message="No pending jobs." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Job', width: '1.4fr' },
        { key: 'queue', label: 'Queue', width: '120px' },
        { key: 'pushed_at', label: 'Pushed', width: '140px' },
      ]"
      :rows="jobs"
    >
      <template #name="{ row }">
        <span>{{ row.name || row.display_name || row.type || row.job_class || '—' }}</span>
      </template>
    </DataTable>
  </div>
</template>

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

const batches = computed(() => current.value.batches ?? []);
const configured = computed(() => current.value.configured ?? true);
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Batches</h1>

    <div
      v-if="! configured"
      class="border border-status-warn/40 bg-status-warn/10 text-status-warn rounded p-3 text-xs"
    >
      Laravel batches are not configured on this installation. Publish the
      <code>queue:batches-table</code> migration to enable them.
    </div>

    <Empty v-if="configured && batches.length === 0" message="No batches recorded." />
    <DataTable
      v-else-if="batches.length > 0"
      :columns="[
        { key: 'name', label: 'Name', width: '1.4fr' },
        { key: 'total_jobs', label: 'Total', width: '90px' },
        { key: 'pending_jobs', label: 'Pending', width: '90px' },
        { key: 'failed_jobs', label: 'Failed', width: '90px' },
        { key: 'created_at', label: 'Created', width: '160px' },
      ]"
      :rows="batches"
    >
      <template #name="{ row }">
        <span>{{ row.name || row.id || '—' }}</span>
      </template>
    </DataTable>
  </div>
</template>

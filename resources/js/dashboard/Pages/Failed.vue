<script setup>
import { computed, ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import MasterDetail from '../components/MasterDetail.vue';
import ExceptionTrace from '../components/ExceptionTrace.vue';
import ConfirmAction from '../components/ConfirmAction.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const failures = computed(() => current.value.failures ?? []);
const total = computed(() => current.value.total ?? failures.value.length);
const recentlyFailed = computed(() => current.value.recent ?? 0);

const selectedId = ref(failures.value[0]?.id ?? null);

watch(failures, (list) => {
  if (! list.find((f) => f.id === selectedId.value)) {
    selectedId.value = list[0]?.id ?? null;
  }
});

const selected = computed(
  () => failures.value.find((f) => f.id === selectedId.value) ?? null
);

async function retry() {
  if (! selected.value) return;
  try {
    await axios.post(`/sunset/jobs/failed/${selected.value.id}/retry`);
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    console.error('Retry failed:', e);
  }
}

async function deleteOne() {
  if (! selected.value) return;
  try {
    await axios.post(`/sunset/jobs/failed/${selected.value.id}/delete`);
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    console.error('Delete failed:', e);
  }
}

function firstLine(s) {
  return (s || '').split('\n')[0];
}

function displayName(item) {
  return item.name || item.display_name || item.type || item.job_class || 'job';
}
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">
      Failed jobs
      <span class="text-sunset-muted text-xs ml-1">{{ total }}</span>
      <span v-if="recentlyFailed" class="text-status-error text-xs ml-2">
        {{ recentlyFailed }} in the last hour
      </span>
    </h1>

    <Empty v-if="failures.length === 0" message="No failed jobs. Nice." />

    <MasterDetail
      v-else
      :items="failures"
      :selected-id="selectedId"
      @select="(it) => (selectedId = it.id)"
    >
      <template #row="{ item }">
        <div class="text-sunset-text font-bold truncate">{{ displayName(item) }}</div>
        <div class="text-status-error text-[10px] truncate">{{ firstLine(item.exception) }}</div>
        <div class="text-sunset-muted text-[10px] truncate">
          {{ item.queue }} · {{ item.failed_at }}
        </div>
      </template>
      <template #detail>
        <div v-if="selected">
          <div class="flex items-center justify-between mb-3">
            <span class="font-bold text-sm">{{ displayName(selected) }}</span>
            <div class="flex gap-2">
              <ConfirmAction label="retry" confirm-label="confirm retry" @confirm="retry" />
              <ConfirmAction
                label="delete"
                confirm-label="confirm delete"
                variant="danger"
                @confirm="deleteOne"
              />
            </div>
          </div>
          <div class="text-xs text-sunset-muted mb-3">
            {{ selected.connection }} · {{ selected.queue }} · {{ selected.failed_at }}
          </div>
          <ExceptionTrace :exception="selected.exception || '(no exception detail)'" />
        </div>
        <div v-else class="text-sunset-muted text-xs">
          Select a failure on the left to inspect.
        </div>
      </template>
    </MasterDetail>
  </div>
</template>

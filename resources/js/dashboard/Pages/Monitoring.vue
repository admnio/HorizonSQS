<script setup>
import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const pinned = computed(() => current.value.pinned ?? []);
const counts = computed(() => current.value.counts ?? {});

const newTag = ref('');
const busy = ref(false);

async function pin() {
  const tag = newTag.value.trim();
  if (! tag || busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/sunset/monitoring/${encodeURIComponent(tag)}/pin`);
    newTag.value = '';
    router.reload({ only: ['pinned', 'counts'] });
  } catch (e) {
    console.error('Pin failed:', e);
  } finally {
    busy.value = false;
  }
}

async function unpin(tag) {
  if (busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/sunset/monitoring/${encodeURIComponent(tag)}/unpin`);
    router.reload({ only: ['pinned', 'counts'] });
  } catch (e) {
    console.error('Unpin failed:', e);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Monitoring</h1>
    <p class="text-xs text-sunset-muted">
      Pin a tag (e.g. <code>tenant:42</code>) to track jobs associated with it.
    </p>

    <div class="flex gap-2">
      <input
        v-model="newTag"
        @keydown.enter="pin"
        placeholder="Tag name, e.g. tenant:42"
        class="flex-1 bg-sunset-card border border-sunset-border rounded px-3 py-2 text-xs font-mono text-sunset-text"
      >
      <button
        @click="pin"
        :disabled="busy || ! newTag.trim()"
        class="px-3 py-2 bg-sunset-accent text-sunset-bg rounded text-xs font-mono disabled:opacity-50"
      >
        Pin
      </button>
    </div>

    <Empty v-if="pinned.length === 0" message="No tags pinned. Add one above." />
    <div v-else class="border border-sunset-border rounded divide-y divide-sunset-border">
      <div
        v-for="tag in pinned"
        :key="tag"
        class="px-3 py-2 flex items-center justify-between text-xs"
      >
        <span class="text-sunset-text font-bold">{{ tag }}</span>
        <div class="flex items-center gap-3">
          <span class="text-sunset-muted tabular-nums">{{ counts[tag] ?? 0 }} jobs</span>
          <button
            @click="unpin(tag)"
            :disabled="busy"
            class="text-sunset-muted hover:text-status-error disabled:opacity-50"
          >
            remove
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

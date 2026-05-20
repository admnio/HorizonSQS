<script setup>
defineProps({
  columns: { type: Array, required: true },     // [{ key, label, width? }]
  rows:    { type: Array, required: true },
  selectable: { type: Boolean, default: true },
});
defineEmits(['select']);
</script>

<template>
  <div class="border border-sunset-border rounded overflow-hidden">
    <div
      class="grid bg-sunset-rail text-sunset-muted text-[9px] uppercase tracking-wide px-2 py-1"
      :style="{ gridTemplateColumns: columns.map(c => c.width || '1fr').join(' ') }"
    >
      <div v-for="col in columns" :key="col.key">{{ col.label }}</div>
    </div>
    <div
      v-for="(row, i) in rows"
      :key="row.id ?? i"
      :class="[
        'grid px-2 py-1.5 border-t border-sunset-border text-xs',
        selectable ? 'hover:bg-sunset-rail cursor-pointer' : '',
      ]"
      :style="{ gridTemplateColumns: columns.map(c => c.width || '1fr').join(' ') }"
      @click="selectable ? $emit('select', row) : null"
    >
      <div v-for="col in columns" :key="col.key" class="truncate">
        <slot :name="col.key" :row="row">{{ row[col.key] }}</slot>
      </div>
    </div>
    <div v-if="rows.length === 0" class="p-4 text-center text-sunset-muted text-xs">No rows</div>
  </div>
</template>

<script setup>
defineProps({
  items: { type: Array, required: true },
  selectedId: { type: [String, Number, null], default: null },
});
defineEmits(['select']);
</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-3">
    <div class="border border-sunset-border rounded max-h-[calc(100vh-160px)] overflow-auto">
      <button
        v-for="item in items"
        :key="item.id"
        :class="[
          'w-full text-left px-3 py-2 border-b border-sunset-border text-xs transition-colors',
          selectedId === item.id ? 'bg-sunset-rail border-l-2 border-l-sunset-accent' : 'hover:bg-sunset-rail/50',
        ]"
        @click="$emit('select', item)"
      >
        <slot name="row" :item="item" />
      </button>
      <div v-if="items.length === 0" class="p-4 text-center text-sunset-muted text-xs">No items</div>
    </div>
    <div class="border border-sunset-border rounded p-4 min-h-[200px]">
      <slot name="detail" />
    </div>
  </div>
</template>

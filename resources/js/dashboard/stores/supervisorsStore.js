import { defineStore } from 'pinia';

export const useSupervisorsStore = defineStore('supervisors', {
  state: () => ({ items: [] }),
  actions: { setItems(items) { this.items = items; } },
});

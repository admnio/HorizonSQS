import { defineStore } from 'pinia';

export const usePollStore = defineStore('poll', {
  state: () => ({ intervalMs: 3000 }),
  actions: { setInterval(ms) { this.intervalMs = ms; } },
});

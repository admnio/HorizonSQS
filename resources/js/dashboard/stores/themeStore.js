import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
  state: () => ({ current: null }),
  actions: { set(t) { this.current = t; } },
});

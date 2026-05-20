import { onMounted, onBeforeUnmount } from 'vue';
import { usePaletteStore } from '../stores/paletteStore.js';

export function useKeyboard() {
  const palette = usePaletteStore();

  function onKey(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      palette.toggle();
    }
    if (e.key === 'Escape' && palette.open) {
      palette.hide();
    }
  }

  onMounted(() => window.addEventListener('keydown', onKey));
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
}

import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

export function usePolling(url, intervalMs = 3000) {
  const data = ref(null);
  const error = ref(null);
  let timer = null;
  let inFlight = false;

  async function tick() {
    if (inFlight) return;
    inFlight = true;
    try {
      const resp = await axios.get(url, { params: { refresh: 1 } });
      data.value = resp.data.props ?? resp.data;
      error.value = null;
    } catch (e) {
      error.value = e;
    } finally {
      inFlight = false;
    }
  }

  onMounted(() => {
    tick();
    timer = setInterval(tick, intervalMs);
  });
  onBeforeUnmount(() => { if (timer) clearInterval(timer); });

  return { data, error, refresh: tick };
}

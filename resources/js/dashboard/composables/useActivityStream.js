import { ref, onUnmounted } from 'vue';

export function useActivityStream() {
  const isOpen = ref(false);
  const error = ref(null);
  let source = null;
  let onEventCb = null;

  function start(url, onEvent) {
    if (source) return;
    onEventCb = onEvent;
    source = new EventSource(url);
    source.onopen = () => { isOpen.value = true; error.value = null; };
    source.onerror = (e) => { isOpen.value = false; error.value = e; };
    source.onmessage = (e) => { /* default 'message' events — not used; we use addEventListener per type */ };

    // Wire all 8 known event types to the same callback.
    const types = [
      'job_failed', 'job_completed', 'job_rate_limited', 'job_queued',
      'worker_process_restarting', 'unable_to_launch_process',
      'long_wait_detected', 'master_supervisor_deployed',
    ];
    types.forEach((t) => {
      source.addEventListener(t, (e) => {
        try {
          const data = JSON.parse(e.data);
          onEventCb && onEventCb(data);
        } catch (_) { /* swallow malformed frames */ }
      });
    });
  }

  function stop() {
    if (!source) return;
    source.close();
    source = null;
    isOpen.value = false;
  }

  onUnmounted(stop);

  return { start, stop, isOpen, error };
}

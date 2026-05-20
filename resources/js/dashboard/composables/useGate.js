// Reads server-rendered gate flags from Inertia shared props.
// Server-side gate is authoritative; this composable exists so future
// components can show/hide UI based on permissions.
import { usePage } from '@inertiajs/vue3';

export function useGate() {
  const page = usePage();
  return {
    canRetryFailed: () => page.props?.gate?.canRetryFailed ?? true,
    canDeleteFailed: () => page.props?.gate?.canDeleteFailed ?? true,
    canPauseSupervisor: () => page.props?.gate?.canPauseSupervisor ?? true,
  };
}

import { ref } from 'vue';

const theme = ref(null); // 'light' | 'dark' | null (follow OS)

function apply(t) {
  const root = document.documentElement;
  if (t === 'dark') root.classList.add('dark');
  else if (t === 'light') root.classList.remove('dark');
  else {
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.classList.toggle('dark', dark);
  }
}

export function useTheme() {
  function bootstrap() {
    const stored = window.localStorage.getItem('sunset.theme');
    theme.value = stored === 'light' || stored === 'dark' ? stored : null;
    apply(theme.value);

    if (theme.value === null && window.matchMedia) {
      const mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', () => apply(null));
    }
  }

  function set(t) {
    theme.value = t;
    if (t === null) window.localStorage.removeItem('sunset.theme');
    else window.localStorage.setItem('sunset.theme', t);
    apply(t);
  }

  function toggle() {
    set(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
  }

  function current() { return theme.value; }

  return { theme, bootstrap, set, toggle, current };
}

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import Layout from './Layout.vue';
import { useTheme } from './composables/useTheme.js';
import '../../css/dashboard/app.css';

createInertiaApp({
  resolve: async (name) => {
    const pages = import.meta.glob('./Pages/**/*.vue');
    // Server sends names like 'Sunset/Overview' — strip the prefix.
    const path = `./Pages/${name.replace(/^Sunset\//, '')}.vue`;
    const loader = pages[path];
    if (! loader) {
      throw new Error(`Sunset dashboard page not found: ${path}`);
    }
    const page = (await loader()).default;
    page.layout ??= Layout;
    return page;
  },
  setup({ el, App, props, plugin }) {
    const pinia = createPinia();
    const app = createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(pinia);

    // Theme bootstrap BEFORE mount so first paint matches preference.
    useTheme().bootstrap();

    app.mount(el);
  },
});

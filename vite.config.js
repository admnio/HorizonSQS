import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  build: {
    outDir: 'public-dist',
    emptyOutDir: true,
    lib: {
      entry: 'resources/js/dashboard/app.js',
      formats: ['iife'],
      name: 'sunsetDashboard',
      fileName: () => 'app.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'app.[ext]',
      },
    },
  },
  plugins: [vue()],
});

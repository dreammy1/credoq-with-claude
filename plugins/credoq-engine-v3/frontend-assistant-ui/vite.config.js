import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    lib: {
      entry: 'src/main.jsx',
      name: 'CredoqFrontendAssistant',
      formats: ['iife'],
      fileName: () => 'frontend-assistant.min.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'frontend-assistant.min.css',
      },
    },
    minify: 'esbuild',
    emptyOutDir: true,
  },
});

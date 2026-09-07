import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    lib: {
      entry: 'src/main.jsx',
      name: 'CredoqCommandCenter',
      formats: ['iife'],
      fileName: () => 'command-center.min.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'command-center.min.css',
      },
    },
    minify: 'esbuild',
    emptyOutDir: true,
  },
});

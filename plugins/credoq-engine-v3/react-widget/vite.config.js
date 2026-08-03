import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env': '{}',
    'process': '{"env":{"NODE_ENV":"production"}}'
  },
  build: {
    outDir: 'dist',
    lib: {
      entry: 'src/main.jsx',
      name: 'CredoQBookingWidget',
      formats: ['iife'],
      fileName: () => 'booking-widget.min.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'booking-widget.min.css',
      },
    },
    minify: 'terser',
    cssCodeSplit: false,
  },
});

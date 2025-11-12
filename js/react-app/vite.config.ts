import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: '.',
    rollupOptions: {
      input: path.resolve(__dirname, 'src/index.tsx'),
      output: {
        entryFileNames: 'dashboard.js',
        chunkFileNames: 'dashboard-[hash].js',
        assetFileNames: 'dashboard-[name][extname]',
        format: 'es',
      },
    },
  },
});

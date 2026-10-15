import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Proxy /api to the Vault24 PHP backend (php -S localhost:8000) so the session cookie stays same-origin in dev.
export default defineConfig({
  plugins: [react()],
  server: { port: 5174, strictPort: true, proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } } },
});

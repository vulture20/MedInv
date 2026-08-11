import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// MedInv frontend dev server. Talks to the Laravel backend (default
// http://localhost:8000, or whatever `php artisan serve --port=` was
// started with — see src/api/client.ts) via Sanctum SPA cookie auth. There
// is no separate API-only port; local dev just runs backend and frontend
// on two ordinary ports. The dev server's own port defaults to 5173 but honors
// MEDINV_PortWeb (from a shell env var or this project's .env — loadEnv's
// 3rd argument "" means read every key, not just VITE_-prefixed ones) so it
// matches the same variable used in docker/. Keep it in sync with
// backend/.env's FRONTEND_URL / SANCTUM_STATEFUL_DOMAINS and
// config/cors.php if you change it.
export default defineConfig(({ mode }) => {
  const env = { ...process.env, ...loadEnv(mode, process.cwd(), '') }
  const port = Number(env.MEDINV_PortWeb) || 5173

  return {
    plugins: [react()],
    server: { port },
  }
})

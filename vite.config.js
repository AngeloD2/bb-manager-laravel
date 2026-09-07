import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'

// The billboard player SPA is built by Laravel's Vite pipeline and served from
// resources/views/player.blade.php, so it shares an origin with the API it talks
// to. That is what lets the board use a relative API base (/api/v1) instead of a
// hard-coded host that has to be rewritten every time the LAN address changes.
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/player/main.jsx'],
      refresh: ['resources/views/**'],
    }),
    react(),
  ],
})

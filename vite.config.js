import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.jsx'], refresh: true }),
        react(),
    ],
    build: { rollupOptions: { output: { manualChunks: { fluent: ['@fluentui/react-components'], icons: ['@fluentui/react-icons'] } } } },
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});

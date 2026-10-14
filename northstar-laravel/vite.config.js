import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

/**
 * Opening the Vite address (http://127.0.0.1:5173) in a browser normally shows a "Laravel Vite" placeholder page.
 * Send page visits on to the Laravel app instead; script, style and HMR requests are untouched.
 */
function redirectPagesToLaravel(appUrl) {
    return {
        name: 'redirect-pages-to-laravel',
        configureServer(server) {
            server.middlewares.use((req, res, next) => {
                if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                    res.statusCode = 302;
                    res.setHeader('Location', appUrl.replace(/\/$/, '') + req.url);
                    return res.end();
                }
                next();
            });
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        plugins: [
            redirectPagesToLaravel(env.APP_URL || 'http://127.0.0.1:8010'),
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: { base: null, includeAbsolute: false },
                },
            }),
            tailwindcss(),
        ],
        // Tailwind runs through its Vite plugin; an inline config stops Vite picking up a postcss.config from parent folders.
        css: {
            postcss: {},
        },
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            // Serve on IPv4 so the page (on 127.0.0.1:8010) can always reach Vite; some browsers fail on [::1].
            host: '127.0.0.1',
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});

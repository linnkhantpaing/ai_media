import '../css/date-picker.css';
import '@fontsource-variable/manrope';
import '@fontsource-variable/noto-sans-myanmar';
import '@fontsource-variable/noto-sans-thai';
import '../css/app.css';
import '../css/auth.css';
import '../css/admin.css';
import '../css/frame.css';
import '../css/locale.css';
import '../css/media.css';
import '../css/project-library.css';
import '../css/loading.css';
import '../css/operations.css';
import '../css/media-creator.css';
import '../css/settings.css';
import '../css/project-defaults.css';
import '../css/storage-settings.css';
import '../css/toast.css';
import '../css/user-accounts.css';
import '../css/tooltip.css';
import '../css/packages.css';
import '../css/subscriptions.css';
import '../css/payment-settings.css';
import '../css/transactions.css';
import '../css/general-settings.css';
import { ThemeProvider } from './lib/theme';
import { LoadingProvider } from './lib/loading';
import { ToastProvider } from './lib/toast';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode, type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

void createInertiaApp({
    title: (title) => title ? title + ' - ' + (document.documentElement.dataset.appName || 'ZATLAN') : (document.documentElement.dataset.appName || 'ZATLAN'),
    resolve: async (name) => {
        const page = await resolvePageComponent<{ default: ComponentType }>(
            './pages/' + name + '.tsx',
            import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx'),
        );

        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <ThemeProvider><ToastProvider><LoadingProvider><App {...props} /></LoadingProvider></ToastProvider></ThemeProvider>
            </StrictMode>,
        );
    },
    progress: false,
});

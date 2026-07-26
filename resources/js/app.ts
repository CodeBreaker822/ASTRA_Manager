import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = import.meta.glob<{ default: DefineComponent }>(
    './pages/**/*.vue',
);
type PageComponent = DefineComponent & {
    layout?: unknown;
};

const isLayoutProps = (value: unknown): value is Record<string, unknown> => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }

    return !(
        'setup' in value ||
        'render' in value ||
        '__name' in value ||
        'template' in value
    );
};

const applyDefaultLayout = async (
    name: string,
    component: PageComponent,
): Promise<PageComponent> => {
    const layoutProps = isLayoutProps(component.layout) ? component.layout : {};

    if (component.layout && !isLayoutProps(component.layout)) {
        return component;
    }

    if (name === 'Welcome' || name.startsWith('workspace/')) {
        component.layout = undefined;

        return component;
    }

    if (name.startsWith('marketing/')) {
        const { default: MarketingLayout } =
            await import('@/layouts/MarketingLayout.vue');
        component.layout = [[MarketingLayout, layoutProps]];

        return component;
    }

    if (name.startsWith('auth/')) {
        const { default: AuthLayout } =
            await import('@/layouts/AuthLayout.vue');
        component.layout = [[AuthLayout, layoutProps]];

        return component;
    }

    if (name.startsWith('settings/')) {
        const [{ default: AppLayout }, { default: SettingsLayout }] =
            await Promise.all([
                import('@/layouts/AppLayout.vue'),
                import('@/layouts/settings/Layout.vue'),
            ]);
        component.layout = [
            [AppLayout, layoutProps],
            [SettingsLayout, layoutProps],
        ];

        return component;
    }

    const { default: AppLayout } = await import('@/layouts/AppLayout.vue');
    component.layout = [[AppLayout, layoutProps]];

    return component;
};

createInertiaApp({
    resolve: async (name) => {
        const loadPage = pages[`./pages/${name}.vue`];

        if (!loadPage) {
            throw new Error(`Page not found: ${name}`);
        }

        const page = await loadPage();

        return applyDefaultLayout(name, page.default);
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#2563eb',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

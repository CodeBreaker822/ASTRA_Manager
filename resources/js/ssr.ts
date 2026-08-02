import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { renderToString } from '@vue/server-renderer';
import { createSSRApp, h } from 'vue';
import type { DefineComponent } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'JERVA Transcriber';
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

    if (name.startsWith('marketing/')) {
        const { default: MarketingLayout } =
            await import('@/layouts/MarketingLayout.vue');
        component.layout = [[MarketingLayout, layoutProps]];

        return component;
    }

    component.layout = undefined;

    return component;
};

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        serverHead: (currentPage) => {
            const seo = currentPage.props.seo as { head?: unknown } | undefined;

            return Array.isArray(seo?.head)
                ? seo.head.filter(
                      (tag): tag is string => typeof tag === 'string',
                  )
                : [];
        },
        resolve: async (name) => {
            const loadPage = pages[`./pages/${name}.vue`];

            if (!loadPage) {
                throw new Error(`Page not found: ${name}`);
            }

            const resolvedPage = await loadPage();

            return applyDefaultLayout(name, resolvedPage.default);
        },
        title: (title) => {
            return title || appName;
        },
        setup: ({ App, props, plugin }) =>
            createSSRApp({ render: () => h(App, props) }).use(plugin),
    }),
);

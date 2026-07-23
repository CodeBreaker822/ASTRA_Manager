import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type SettingsTab = 'profile' | 'security' | 'appearance' | 'billing';

const settingsTabs: SettingsTab[] = [
    'profile',
    'security',
    'appearance',
    'billing',
];

function urlFor(pageUrl: string, tab?: SettingsTab): string {
    const url = new URL(pageUrl, window.location.origin);

    if (tab) {
        url.searchParams.set('settings', tab);
    } else {
        url.searchParams.delete('settings');
    }

    return `${url.pathname}${url.search}${url.hash}`;
}

export function useSettingsModal() {
    const page = usePage();
    const activeTab = computed<SettingsTab | null>(() => {
        const tab = new URL(page.url, window.location.origin).searchParams.get(
            'settings',
        );

        return settingsTabs.includes(tab as SettingsTab)
            ? (tab as SettingsTab)
            : null;
    });

    return {
        activeTab,
        closeHref: computed(() => urlFor(page.url)),
        settingsHref: (tab: SettingsTab) => urlFor(page.url, tab),
    };
}

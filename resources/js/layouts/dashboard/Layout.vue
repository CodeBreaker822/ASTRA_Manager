<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    FileText,
    KeyRound,
    LayoutGrid,
    Newspaper,
    Tags,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import AppLayout from '@/layouts/AppLayout.vue';
import type { NavItem } from '@/types';

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();

const navItems = computed<NavItem[]>(() => [
    {
        title: 'Overview',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    ...(page.props.auth.canManageBlog
        ? [
              {
                  title: 'Blog',
                  href: '/dashboard/blog',
                  icon: Newspaper,
              },
          ]
        : []),
    ...(page.props.auth.canManagePricing
        ? [
              {
                  title: 'Pricing',
                  href: '/dashboard/pricing',
                  icon: Tags,
              },
          ]
        : []),
    ...(page.props.auth.canManagePages
        ? [
              {
                  title: 'Pages',
                  href: '/dashboard/pages/features',
                  icon: FileText,
              },
          ]
        : []),
    ...(page.props.auth.canManageUsers
        ? [
              {
                  title: 'Users',
                  href: '/settings/users',
                  icon: UsersRound,
              },
          ]
        : []),
    ...(page.props.auth.canManageApi
        ? [
              {
                  title: 'API',
                  href: '/settings/api',
                  icon: KeyRound,
                  inertia: false,
              },
          ]
        : []),
]);
</script>

<template>
    <AppLayout
        :breadcrumbs="[
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
        ]"
    >
        <div class="mx-auto max-w-5xl px-6 py-8">
            <Heading
                title="Dashboard"
                description="Manage editable JERVA Web content"
            />

            <div class="mt-8 flex flex-col gap-8 lg:flex-row">
                <aside class="w-full lg:w-56">
                    <nav
                        class="flex flex-col gap-1"
                        aria-label="Dashboard sections"
                    >
                        <Button
                            v-for="item in navItems"
                            :key="String(item.href)"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                {
                                    'bg-blue-50 text-blue-700':
                                        isCurrentOrParentUrl(item.href),
                                },
                            ]"
                            as-child
                        >
                            <Link
                                v-if="item.inertia !== false"
                                :href="item.href"
                            >
                                <component :is="item.icon" class="size-4" />
                                {{ item.title }}
                            </Link>
                            <a v-else :href="String(item.href)">
                                <component :is="item.icon" class="size-4" />
                                {{ item.title }}
                            </a>
                        </Button>
                    </nav>
                </aside>

                <Separator class="lg:hidden" />

                <section class="min-w-0 flex-1">
                    <slot />
                </section>
            </div>
        </div>
    </AppLayout>
</template>

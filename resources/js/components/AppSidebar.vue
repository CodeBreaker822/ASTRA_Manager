<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    FileText,
    KeyRound,
    LayoutGrid,
    Newspaper,
    Tags,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Workspace',
        href: '/workspace',
        icon: LayoutGrid,
    },
    ...(page.props.auth.canAccessDashboard
        ? [
              {
                  title: 'Dashboard',
                  href: dashboard(),
                  icon: BriefcaseBusiness,
              },
          ]
        : []),
]);

const adminNavItems = computed<NavItem[]>(() => [
    ...(page.props.auth.canManageUsers || page.props.auth.canManagePermissions
        ? [
              {
                  title: 'User Management',
                  href: '/dashboard/users',
                  icon: UsersRound,
              },
          ]
        : []),
    ...(page.props.auth.canManageApi
        ? [
              {
                  title: 'API Management',
                  href: '/dashboard/api',
                  icon: KeyRound,
              },
          ]
        : []),
]);

const cmsNavItems = computed<NavItem[]>(() => [
    ...(page.props.auth.canManageBlog
        ? [
              {
                  title: 'Blog Manager',
                  href: '/dashboard/blog',
                  icon: Newspaper,
              },
          ]
        : []),
    ...(page.props.auth.canManagePricing
        ? [
              {
                  title: 'Pricing Manager',
                  href: '/dashboard/pricing',
                  icon: Tags,
              },
          ]
        : []),
    ...(page.props.auth.canManagePages
        ? [
              {
                  title: 'Page Manager',
                  href: '/dashboard/pages/features',
                  icon: FileText,
              },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link
                            :href="
                                page.props.auth.canAccessDashboard
                                    ? dashboard()
                                    : '/workspace'
                            "
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length"
                label="Admin Management"
                :items="adminNavItems"
            />
            <NavMain
                v-if="cmsNavItems.length"
                label="CMS Managers"
                :items="cmsNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

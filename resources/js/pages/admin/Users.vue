<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Save, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update, destroy } from '@/routes/admin/users';
import type { User } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'User Manager',
                href: index().url,
            },
        ],
    },
});

defineProps<{
    users: User[];
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);
const editingUserId = ref<number | null>(null);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
});

const submitCreate = () => {
    createForm.post(store().url, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const startEdit = (user: User) => {
    editingUserId.value = user.id;
    editForm.clearErrors();
    editForm.defaults({
        name: user.name,
        email: user.email,
        password: '',
    });
    editForm.reset();
};

const cancelEdit = () => {
    editingUserId.value = null;
    editForm.clearErrors();
    editForm.reset();
};

const submitEdit = (user: User) => {
    editForm.put(update(user).url, {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
};

const deleteUser = (user: User) => {
    if (user.id === currentUserId.value) {
        return;
    }

    router.delete(destroy(user).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="User Manager" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Card class="rounded-lg">
            <CardHeader>
                <CardTitle>User Manager</CardTitle>
                <CardDescription>
                    Add users and maintain access accounts for ASTRA AI Server.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_auto]"
                    @submit.prevent="submitCreate"
                >
                    <div class="grid gap-2">
                        <Label for="new-name">Name</Label>
                        <Input
                            id="new-name"
                            v-model="createForm.name"
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-email">Email</Label>
                        <Input
                            id="new-email"
                            v-model="createForm.email"
                            type="email"
                            autocomplete="email"
                            placeholder="name@example.com"
                        />
                        <InputError :message="createForm.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-password">Password</Label>
                        <Input
                            id="new-password"
                            v-model="createForm.password"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Password"
                        />
                        <InputError :message="createForm.errors.password" />
                    </div>

                    <Button
                        type="submit"
                        class="self-end"
                        :disabled="createForm.processing"
                    >
                        <Spinner v-if="createForm.processing" />
                        <Plus v-else class="size-4" />
                        Add User
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-lg">
            <CardHeader>
                <CardTitle>Users</CardTitle>
                <CardDescription>
                    Registered accounts that can sign in to the server.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="overflow-hidden rounded-md border">
                    <div
                        class="hidden grid-cols-[1fr_1.2fr_160px_150px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium text-muted-foreground md:grid"
                    >
                        <div>Name</div>
                        <div>Email</div>
                        <div>Status</div>
                        <div class="text-right">Actions</div>
                    </div>

                    <div
                        v-for="user in users"
                        :key="user.id"
                        class="grid gap-3 border-b px-4 py-4 last:border-b-0 md:grid-cols-[1fr_1.2fr_160px_150px] md:items-center md:gap-4"
                    >
                        <template v-if="editingUserId === user.id">
                            <div class="grid gap-2">
                                <Label :for="`name-${user.id}`" class="md:sr-only">
                                    Name
                                </Label>
                                <Input
                                    :id="`name-${user.id}`"
                                    v-model="editForm.name"
                                    autocomplete="name"
                                />
                                <InputError :message="editForm.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`email-${user.id}`" class="md:sr-only">
                                    Email
                                </Label>
                                <Input
                                    :id="`email-${user.id}`"
                                    v-model="editForm.email"
                                    type="email"
                                    autocomplete="email"
                                />
                                <InputError :message="editForm.errors.email" />
                                <Input
                                    v-model="editForm.password"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="New password optional"
                                />
                                <InputError :message="editForm.errors.password" />
                            </div>

                            <div>
                                <Badge
                                    :variant="user.email_verified_at ? 'default' : 'secondary'"
                                >
                                    {{ user.email_verified_at ? 'Verified' : 'Unverified' }}
                                </Badge>
                            </div>

                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    :disabled="editForm.processing"
                                    @click="submitEdit(user)"
                                >
                                    <Spinner v-if="editForm.processing" />
                                    <Save v-else class="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    :disabled="editForm.processing"
                                    @click="cancelEdit"
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>
                        </template>

                        <template v-else>
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ user.name }}
                                </div>
                                <div class="text-sm text-muted-foreground md:hidden">
                                    {{ user.email }}
                                </div>
                            </div>

                            <div class="hidden truncate text-sm md:block">
                                {{ user.email }}
                            </div>

                            <div>
                                <Badge
                                    :variant="user.email_verified_at ? 'default' : 'secondary'"
                                >
                                    {{ user.email_verified_at ? 'Verified' : 'Unverified' }}
                                </Badge>
                            </div>

                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    @click="startEdit(user)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="destructive"
                                    :disabled="user.id === currentUserId"
                                    @click="deleteUser(user)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </template>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

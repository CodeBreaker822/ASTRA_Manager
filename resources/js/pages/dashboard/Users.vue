<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Save, ShieldCheck, Trash2, X } from '@lucide/vue';
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
import DashboardLayout from '@/layouts/dashboard/Layout.vue';
import type { User } from '@/types';

type GateItem = {
    name: string;
    label: string;
};

type Position = {
    id: number;
    position_name: string;
    permissions: string[];
};

type ManagedUser = User & {
    position_id: number | null;
    user_status: string | null;
    position?: {
        id: number;
        position_name: string;
    } | null;
    license?: {
        id: number;
        app_name: string;
        token_suffix: string | null;
        masked_token: string;
        is_active: boolean;
    } | null;
};

defineOptions({
    layout: DashboardLayout,
});

defineProps<{
    users: ManagedUser[];
    positions: Position[];
    gates: Record<string, GateItem[]>;
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);
const editingUserId = ref<number | null>(null);
const editingPositionId = ref<number | null>(null);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    position_id: '',
    user_status: 'active',
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    position_id: '',
    user_status: 'active',
});

const positionForm = useForm({
    position_name: '',
    permissions: [] as string[],
});

const submitCreate = () => {
    createForm.post('/dashboard/users', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const startEdit = (user: ManagedUser) => {
    editingUserId.value = user.id;
    editForm.clearErrors();
    editForm.defaults({
        name: user.name,
        email: user.email,
        password: '',
        position_id: user.position_id ? String(user.position_id) : '',
        user_status: user.user_status || 'active',
    });
    editForm.reset();
};

const cancelEdit = () => {
    editingUserId.value = null;
    editForm.clearErrors();
    editForm.reset();
};

const submitEdit = (user: ManagedUser) => {
    editForm.put(`/dashboard/users/${user.id}`, {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
};

const deleteUser = (user: ManagedUser) => {
    if (user.id === currentUserId.value) {
        return;
    }

    router.delete(`/dashboard/users/${user.id}`, {
        preserveScroll: true,
    });
};

const togglePermission = (permission: string) => {
    const permissions = positionForm.permissions;
    positionForm.permissions = permissions.includes(permission)
        ? permissions.filter((item) => item !== permission)
        : [...permissions, permission];
};

const startPositionEdit = (position: Position) => {
    editingPositionId.value = position.id;
    positionForm.clearErrors();
    positionForm.defaults({
        position_name: position.position_name,
        permissions: [...position.permissions],
    });
    positionForm.reset();
};

const cancelPositionEdit = () => {
    editingPositionId.value = null;
    positionForm.clearErrors();
    positionForm.reset();
};

const submitPosition = (position?: Position) => {
    const options = {
        preserveScroll: true,
        onSuccess: () => cancelPositionEdit(),
    };

    if (position) {
        positionForm.put(`/dashboard/users/positions/${position.id}`, options);

        return;
    }

    positionForm.post('/dashboard/users/positions', options);
};

const removePosition = (position: Position) => {
    router.delete(`/dashboard/users/positions/${position.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="User Management" />

    <div class="space-y-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-950">
                User Management
            </h1>
            <p class="mt-1 text-sm text-slate-700">
                Manage users, positions, and gate permissions for JERVA Web.
            </p>
        </div>

        <Card class="rounded-lg">
            <CardHeader>
                <CardTitle>Create User</CardTitle>
                <CardDescription>
                    Add a new account and assign an initial position.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 xl:grid-cols-[1fr_1fr_1fr_180px_140px_auto]"
                    @submit.prevent="submitCreate"
                >
                    <div class="grid gap-2">
                        <Label for="new-name">Name</Label>
                        <Input
                            id="new-name"
                            v-model="createForm.name"
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
                            placeholder="Password"
                        />
                        <InputError :message="createForm.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-position">Position</Label>
                        <select
                            id="new-position"
                            v-model="createForm.position_id"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">No position</option>
                            <option
                                v-for="position in positions"
                                :key="position.id"
                                :value="String(position.id)"
                            >
                                {{ position.position_name }}
                            </option>
                        </select>
                        <InputError :message="createForm.errors.position_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-status">Status</Label>
                        <select
                            id="new-status"
                            v-model="createForm.user_status"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="active">Active</option>
                            <option value="banned">Banned</option>
                            <option value="deactivated">Deactivated</option>
                        </select>
                        <InputError :message="createForm.errors.user_status" />
                    </div>

                    <Button
                        type="submit"
                        class="self-end"
                        :disabled="createForm.processing"
                    >
                        <Spinner v-if="createForm.processing" />
                        <Plus v-else class="size-4" />
                        Add
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-lg">
            <CardHeader>
                <CardTitle>Users</CardTitle>
                <CardDescription>
                    Assign users to positions and control account status.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="overflow-hidden rounded-md border">
                    <div
                        class="hidden grid-cols-[1fr_1.1fr_170px_120px_120px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium text-muted-foreground lg:grid"
                    >
                        <div>Name</div>
                        <div>Email</div>
                        <div>Position</div>
                        <div>Status</div>
                        <div class="text-right">Actions</div>
                    </div>

                    <div
                        v-for="user in users"
                        :key="user.id"
                        class="grid gap-3 border-b px-4 py-4 last:border-b-0 lg:grid-cols-[1fr_1.1fr_170px_120px_120px] lg:items-center lg:gap-4"
                    >
                        <template v-if="editingUserId === user.id">
                            <Input v-model="editForm.name" aria-label="Name" />

                            <div class="grid gap-2">
                                <Input
                                    v-model="editForm.email"
                                    type="email"
                                    aria-label="Email"
                                />
                                <Input
                                    v-model="editForm.password"
                                    type="password"
                                    placeholder="New password optional"
                                />
                            </div>

                            <select
                                v-model="editForm.position_id"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">No position</option>
                                <option
                                    v-for="position in positions"
                                    :key="position.id"
                                    :value="String(position.id)"
                                >
                                    {{ position.position_name }}
                                </option>
                            </select>

                            <select
                                v-model="editForm.user_status"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="active">Active</option>
                                <option value="banned">Banned</option>
                                <option value="deactivated">Deactivated</option>
                            </select>

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
                                    @click="cancelEdit"
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>

                            <div
                                class="grid gap-2 rounded-md border bg-muted/30 p-3 lg:col-span-5"
                            >
                                <div
                                    class="flex items-center gap-2 text-sm font-medium"
                                >
                                    <KeyRound class="size-4" />
                                    User API Token
                                </div>
                                <Input
                                    v-if="user.license?.masked_token"
                                    :model-value="user.license.masked_token"
                                    readonly
                                    aria-label="User API token"
                                    class="font-mono text-xs"
                                />
                                <p v-else class="text-sm text-muted-foreground">
                                    No user API token generated.
                                </p>
                            </div>
                        </template>

                        <template v-else>
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ user.name }}
                                </div>
                                <div
                                    class="text-sm text-muted-foreground lg:hidden"
                                >
                                    {{ user.email }}
                                </div>
                            </div>

                            <div class="hidden truncate text-sm lg:block">
                                {{ user.email }}
                            </div>

                            <div class="text-sm">
                                {{
                                    user.position?.position_name ||
                                    'No position'
                                }}
                            </div>

                            <div>
                                <Badge
                                    :variant="
                                        user.user_status === 'active'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        user.user_status === 'banned'
                                            ? 'Banned'
                                            : user.user_status === 'deactivated'
                                              ? 'Deactivated'
                                              : 'Active'
                                    }}
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

        <Card class="rounded-lg">
            <CardHeader>
                <CardTitle>Positions And Gates</CardTitle>
                <CardDescription>
                    Permissions are assigned to positions, then users inherit
                    access from their position.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-5">
                <form class="grid gap-4" @submit.prevent="submitPosition()">
                    <div class="grid gap-2 md:max-w-sm">
                        <Label for="position-name">New position</Label>
                        <Input
                            id="position-name"
                            v-model="positionForm.position_name"
                            placeholder="Administrator"
                        />
                        <InputError
                            :message="positionForm.errors.position_name"
                        />
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="(items, category) in gates"
                            :key="category"
                            class="rounded-md border p-3"
                        >
                            <div
                                class="mb-3 flex items-center gap-2 text-sm font-medium"
                            >
                                <ShieldCheck class="size-4" />
                                {{ category }}
                            </div>
                            <label
                                v-for="gate in items"
                                :key="gate.name"
                                class="flex items-center gap-2 py-1 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="
                                        positionForm.permissions.includes(
                                            gate.name,
                                        )
                                    "
                                    @change="togglePermission(gate.name)"
                                />
                                <span>{{ gate.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            :disabled="
                                positionForm.processing ||
                                editingPositionId !== null
                            "
                        >
                            <Spinner
                                v-if="
                                    positionForm.processing &&
                                    editingPositionId === null
                                "
                            />
                            <Plus v-else class="size-4" />
                            Add Position
                        </Button>
                        <Button
                            v-if="editingPositionId !== null"
                            type="button"
                            variant="outline"
                            @click="cancelPositionEdit"
                        >
                            Cancel edit
                        </Button>
                    </div>
                </form>

                <div class="overflow-hidden rounded-md border">
                    <div
                        v-for="position in positions"
                        :key="position.id"
                        class="border-b p-4 last:border-b-0"
                    >
                        <div
                            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                        >
                            <div class="space-y-2">
                                <div class="font-medium">
                                    {{ position.position_name }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Badge
                                        v-for="permission in position.permissions"
                                        :key="permission"
                                        variant="secondary"
                                    >
                                        {{ permission }}
                                    </Badge>
                                    <span
                                        v-if="position.permissions.length === 0"
                                        class="text-sm text-muted-foreground"
                                    >
                                        No permissions
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    @click="startPositionEdit(position)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="destructive"
                                    @click="removePosition(position)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <form
                            v-if="editingPositionId === position.id"
                            class="mt-4 grid gap-4 rounded-md bg-muted/40 p-3"
                            @submit.prevent="submitPosition(position)"
                        >
                            <Input v-model="positionForm.position_name" />
                            <div
                                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                            >
                                <div
                                    v-for="(items, category) in gates"
                                    :key="category"
                                    class="rounded-md border bg-background p-3"
                                >
                                    <div class="mb-3 text-sm font-medium">
                                        {{ category }}
                                    </div>
                                    <label
                                        v-for="gate in items"
                                        :key="gate.name"
                                        class="flex items-center gap-2 py-1 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            class="size-4"
                                            :checked="
                                                positionForm.permissions.includes(
                                                    gate.name,
                                                )
                                            "
                                            @change="
                                                togglePermission(gate.name)
                                            "
                                        />
                                        <span>{{ gate.label }}</span>
                                    </label>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    type="submit"
                                    :disabled="positionForm.processing"
                                >
                                    <Spinner v-if="positionForm.processing" />
                                    <Save v-else class="size-4" />
                                    Save Position
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="cancelPositionEdit"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

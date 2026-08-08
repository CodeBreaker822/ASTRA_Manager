<x-layouts.dashboard title="User Management">
    <div class="space-y-4" x-data="{ editingUser: null, editingPosition: null }">
        <div>
            <h1 class="text-xl font-semibold text-slate-950">User Management</h1>
            <p class="mt-1 text-sm text-slate-700">
                Manage users, positions, and gate permissions for JERVA Transcriber.
            </p>
        </div>

        <x-ui.card class="rounded-lg" title="Create User" description="Add a new account and assign an initial position.">
            <form method="POST" action="{{ route('dashboard.users.store') }}"
                  class="grid gap-4 xl:grid-cols-[1.2fr_1fr_180px_140px_auto]">
                @csrf

                <div class="grid gap-2">
                    <x-ui.label for="new-email">Email</x-ui.label>
                    <x-ui.input id="new-email" name="email" type="email" placeholder="name@example.com"
                                value="{{ old('email') }}" />
                    <x-ui.input-error name="email" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="new-password">Password</x-ui.label>
                    <x-ui.input id="new-password" name="password" type="password" placeholder="Password" />
                    <x-ui.input-error name="password" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="new-position">Position</x-ui.label>
                    <x-ui.select id="new-position" name="position_id" class="h-9"
                                 :options="$positionOptions" :selected="old('position_id')" />
                    <x-ui.input-error name="position_id" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="new-status">Status</x-ui.label>
                    <x-ui.select id="new-status" name="user_status" class="h-9"
                                 :options="$statusOptions" :selected="old('user_status', 'active')" />
                    <x-ui.input-error name="user_status" />
                </div>

                <x-ui.submit class="self-end">
                    <x-icon name="plus" />
                    Add
                </x-ui.submit>
            </form>
        </x-ui.card>

        <x-ui.card class="rounded-lg" title="Users" description="Assign users to positions and control account status.">
            <div class="overflow-hidden rounded-md border">
                <div class="hidden grid-cols-[1.4fr_170px_120px_120px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium text-muted-foreground lg:grid">
                    <div>Email</div>
                    <div>Position</div>
                    <div>Status</div>
                    <div class="text-right">Actions</div>
                </div>

                @foreach ($users as $user)
                    <div class="border-b px-4 py-4 last:border-b-0">
                        <div class="grid gap-3 lg:grid-cols-[1.4fr_170px_120px_120px] lg:items-center lg:gap-4"
                             x-show="editingUser !== {{ $user['id'] }}">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ $user['email'] }}</div>
                            </div>

                            <div class="text-sm">{{ $user['position']['position_name'] ?? 'No position' }}</div>

                            <div>
                                <x-ui.badge :variant="($user['user_status'] ?? 'active') === 'active' ? 'default' : 'secondary'">
                                    {{ $statusOptions[$user['user_status'] ?? 'active'] ?? 'Active' }}
                                </x-ui.badge>
                            </div>

                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="icon" variant="outline"
                                             x-on:click="editingUser = {{ $user['id'] }}" aria-label="Edit user">
                                    <x-icon name="pencil" />
                                </x-ui.button>

                                <form method="POST" action="{{ route('dashboard.users.destroy', $user['id']) }}"
                                      x-on:submit="if (! confirm('Delete this user?')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.submit size="icon" variant="destructive"
                                                 :disabled="$user['id'] === $currentUserId" aria-label="Delete user">
                                        <x-icon name="trash-2" />
                                    </x-ui.submit>
                                </form>
                            </div>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('dashboard.users.update', $user['id']) }}"
                            class="grid gap-3 lg:grid-cols-[1.4fr_170px_120px_120px] lg:items-center lg:gap-4"
                            x-cloak
                            x-show="editingUser === {{ $user['id'] }}"
                        >
                            @csrf
                            @method('PUT')

                            <div class="grid gap-2">
                                <x-ui.input name="email" type="email" aria-label="Email" value="{{ $user['email'] }}" />
                                <x-ui.input name="password" type="password" placeholder="New password optional" />
                            </div>

                            <x-ui.select name="position_id" class="h-9"
                                         :options="$positionOptions" :selected="$user['position_id']" />

                            <x-ui.select name="user_status" class="h-9"
                                         :options="$statusOptions" :selected="$user['user_status'] ?? 'active'" />

                            <div class="flex justify-end gap-2">
                                <x-ui.submit size="icon" aria-label="Save user">
                                    <x-icon name="save" />
                                </x-ui.submit>
                                <x-ui.button type="button" size="icon" variant="outline"
                                             x-on:click="editingUser = null" aria-label="Cancel">
                                    <x-icon name="x" />
                                </x-ui.button>
                            </div>

                            <div class="grid gap-2 rounded-md border bg-muted/30 p-3 lg:col-span-4">
                                <div class="flex items-center gap-2 text-sm font-medium">
                                    <x-icon name="key-round" />
                                    User API Token
                                </div>
                                @if (! empty($user['license']['masked_token']))
                                    <x-ui.input value="{{ $user['license']['masked_token'] }}" readonly
                                                aria-label="User API token" class="font-mono text-xs" />
                                @else
                                    <p class="text-sm text-muted-foreground">No user API token generated.</p>
                                @endif
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card
            class="rounded-lg"
            title="Positions And Gates"
            description="Permissions are assigned to positions, then users inherit access from their position."
        >
            <div class="space-y-5">
                <form method="POST" action="{{ route('dashboard.users.positions.store') }}" class="grid gap-4">
                    @csrf

                    <div class="grid gap-2 md:max-w-sm">
                        <x-ui.label for="position-name">New position</x-ui.label>
                        <x-ui.input id="position-name" name="position_name" placeholder="Administrator"
                                    value="{{ old('position_name') }}" />
                        <x-ui.input-error name="position_name" />
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($gates as $category => $items)
                            <div class="rounded-md border p-3">
                                <div class="mb-3 flex items-center gap-2 text-sm font-medium">
                                    <x-icon name="shield-check" />
                                    {{ $category }}
                                </div>
                                @foreach ($items as $gate)
                                    <label class="flex items-center gap-2 py-1 text-sm">
                                        <input type="checkbox" name="permissions[]" value="{{ $gate['name'] }}"
                                               class="size-4"
                                               @checked(in_array($gate['name'], old('permissions', []), true))>
                                        <span>{{ $gate['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div class="flex gap-2">
                        <x-ui.submit>
                            <x-icon name="plus" />
                            Add Position
                        </x-ui.submit>
                    </div>
                </form>

                <div class="overflow-hidden rounded-md border">
                    @foreach ($positions as $position)
                        <div class="border-b p-4 last:border-b-0">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div class="space-y-2">
                                    <div class="font-medium">{{ $position['position_name'] }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        @forelse ($position['permissions'] as $permission)
                                            <x-ui.badge variant="secondary">{{ $permission }}</x-ui.badge>
                                        @empty
                                            <span class="text-sm text-muted-foreground">No permissions</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <x-ui.button type="button" size="icon" variant="outline"
                                                 x-on:click="editingPosition = editingPosition === {{ $position['id'] }} ? null : {{ $position['id'] }}"
                                                 aria-label="Edit position">
                                        <x-icon name="pencil" />
                                    </x-ui.button>

                                    <form method="POST" action="{{ route('dashboard.users.positions.destroy', $position['id']) }}"
                                          x-on:submit="if (! confirm('Delete this position?')) $event.preventDefault()">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.submit size="icon" variant="destructive" aria-label="Delete position">
                                            <x-icon name="trash-2" />
                                        </x-ui.submit>
                                    </form>
                                </div>
                            </div>

                            <form
                                method="POST"
                                action="{{ route('dashboard.users.positions.update', $position['id']) }}"
                                class="mt-4 grid gap-4 rounded-md bg-muted/40 p-3"
                                x-cloak
                                x-show="editingPosition === {{ $position['id'] }}"
                            >
                                @csrf
                                @method('PUT')

                                <x-ui.input name="position_name" value="{{ $position['position_name'] }}" />

                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($gates as $category => $items)
                                        <div class="rounded-md border bg-background p-3">
                                            <div class="mb-3 text-sm font-medium">{{ $category }}</div>
                                            @foreach ($items as $gate)
                                                <label class="flex items-center gap-2 py-1 text-sm">
                                                    <input type="checkbox" name="permissions[]" value="{{ $gate['name'] }}"
                                                           class="size-4"
                                                           @checked(in_array($gate['name'], $position['permissions']->all(), true))>
                                                    <span>{{ $gate['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex gap-2">
                                    <x-ui.submit>
                                        <x-icon name="save" />
                                        Save Position
                                    </x-ui.submit>
                                    <x-ui.button type="button" variant="outline" x-on:click="editingPosition = null">
                                        Cancel
                                    </x-ui.button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.dashboard>

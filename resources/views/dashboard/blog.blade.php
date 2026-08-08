<x-layouts.dashboard title="Blog Manager">
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">Blog</h1>
                <p class="mt-1 text-sm text-slate-700">Manage public posts, drafts, and publish status.</p>
            </div>
            <x-ui.button href="/dashboard/blog/create">
                <x-icon name="plus" />
                New post
            </x-ui.button>
        </div>

        @if ($posts->isEmpty())
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <p class="text-sm text-slate-700">No posts are ready for editing yet.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="hidden grid-cols-[1fr_120px_120px_140px_190px] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-600 lg:grid">
                    <div>Title</div>
                    <div>Status</div>
                    <div>Date</div>
                    <div>Author</div>
                    <div class="text-right">Actions</div>
                </div>

                @foreach ($posts as $post)
                    <div class="grid gap-3 border-b border-slate-200 px-4 py-4 last:border-b-0 lg:grid-cols-[1fr_120px_120px_140px_190px] lg:items-center lg:gap-4">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-slate-950">{{ $post['title'] }}</div>
                            <div class="truncate text-sm text-slate-600">/blog/{{ $post['slug'] }}</div>
                        </div>

                        <div>
                            <x-ui.badge :class="$post['status'] === 'published'
                                ? 'bg-blue-100 text-blue-800'
                                : 'bg-slate-100 text-slate-700'">
                                {{ $post['status'] }}
                            </x-ui.badge>
                        </div>

                        <div class="text-sm text-slate-700">{{ $post['date'] ?: 'Draft' }}</div>
                        <div class="truncate text-sm text-slate-700">{{ $post['author'] ?: 'Unknown' }}</div>

                        <div class="flex flex-wrap justify-end gap-2">
                            <x-ui.button href="/dashboard/blog/{{ $post['id'] }}/edit" variant="outline" size="sm">
                                Edit
                            </x-ui.button>

                            <form method="POST" action="{{ route('dashboard.blog.publish', $post['id']) }}">
                                @csrf
                                <x-ui.submit variant="outline" size="sm">
                                    {{ $post['status'] === 'published' ? 'Unpublish' : 'Publish' }}
                                </x-ui.submit>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('dashboard.blog.destroy', $post['id']) }}"
                                x-on:submit="if (! confirm(@js('Delete "'.$post['title'].'"?'))) $event.preventDefault()"
                            >
                                @csrf
                                @method('DELETE')
                                <x-ui.submit variant="destructive" size="sm">Delete</x-ui.submit>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.dashboard>

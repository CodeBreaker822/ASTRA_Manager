<x-layouts.dashboard :title="$pageTitle">
    <form
        method="POST"
        action="{{ $formAction }}"
        enctype="multipart/form-data"
        class="space-y-6"
        x-data="{
            title: @js(old('title', $post['title'] ?? '')),
            slug: @js(old('slug', $post['slug'] ?? '')),
            slugTouched: {{ filled($post['slug'] ?? null) ? 'true' : 'false' }},
            body: @js(old('body_markdown', $post['body_markdown'] ?? '')),
            preview: @js($previewHtml),
            coverName: '',
            timer: null,
            slugify(value) {
                return value.toLowerCase().trim()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
            },
            onTitle() {
                if (! this.slugTouched) {
                    this.slug = this.slugify(this.title);
                }
            },
            onBody() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.refreshPreview(), 400);
            },
            async refreshPreview() {
                try {
                    const response = await fetch(@js(route('dashboard.blog.preview')), {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ body_markdown: this.body }),
                    });

                    if (! response.ok) {
                        throw new Error('Preview could not be refreshed.');
                    }

                    this.preview = (await response.json()).html;
                    this.previewFailed = false;
                } catch (e) {
                    if (! this.previewFailed) {
                        window.showNotification('Preview could not be refreshed.', 'error');
                        this.previewFailed = true;
                    }
                }
            },
            previewFailed: false,
        }"
    >
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="/dashboard/blog"
                   class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
                    <x-icon name="arrow-left" />
                    Blog
                </a>
                <h1 class="mt-3 text-xl font-semibold text-slate-950">{{ $pageTitle }}</h1>
            </div>

            <x-ui.submit>
                <x-icon name="save" />
                Save
            </x-ui.submit>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-5">
                <div class="grid gap-2">
                    <x-ui.label for="title">Title</x-ui.label>
                    <x-ui.input id="title" name="title" class="h-11" x-model="title" x-on:input="onTitle()" />
                    <x-ui.input-error name="title" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="slug">Slug</x-ui.label>
                    <x-ui.input id="slug" name="slug" class="h-11" x-model="slug" x-on:input="slugTouched = true" />
                    <x-ui.input-error name="slug" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="excerpt">Excerpt</x-ui.label>
                    <x-ui.textarea id="excerpt" name="excerpt" rows="3" class="min-h-24">{{ old('excerpt', $post['excerpt'] ?? '') }}</x-ui.textarea>
                    <x-ui.input-error name="excerpt" />
                </div>

                <div class="grid gap-2">
                    <x-ui.label for="body">Body</x-ui.label>
                    <x-ui.textarea
                        id="body"
                        name="body_markdown"
                        rows="18"
                        class="min-h-[420px] font-mono leading-6"
                        x-model="body"
                        x-on:input="onBody()"
                    >{{ old('body_markdown', $post['body_markdown'] ?? '') }}</x-ui.textarea>
                    <x-ui.input-error name="body_markdown" />
                </div>
            </div>

            <aside class="space-y-5">
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="grid gap-2">
                        <x-ui.label for="status">Status</x-ui.label>
                        <x-ui.select
                            id="status"
                            name="status"
                            :options="['draft' => 'Draft', 'published' => 'Published']"
                            :selected="old('status', $post['status'] ?? 'draft')"
                        />
                    </div>

                    <div class="mt-4 grid gap-2">
                        <x-ui.label for="published_at">Published date</x-ui.label>
                        <x-ui.input
                            id="published_at"
                            name="published_at"
                            type="datetime-local"
                            class="h-11"
                            value="{{ old('published_at', $post['published_at'] ?? '') }}"
                        />
                        <x-ui.input-error name="published_at" />
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <x-ui.label for="cover">Cover image</x-ui.label>
                    <label for="cover"
                           class="mt-3 flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-sm text-slate-600 hover:border-blue-300 hover:bg-blue-50">
                        <x-icon name="image" class="size-5 text-blue-600" />
                        <span x-text="coverName || 'Choose jpg, png, or webp'">Choose jpg, png, or webp</span>
                    </label>
                    <input
                        id="cover"
                        name="cover"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="sr-only"
                        x-on:change="coverName = $event.target.files[0]?.name ?? ''"
                    >
                    <x-ui.input-error name="cover" />

                    @if (! empty($post['cover_url']))
                        <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="remove_cover" value="0">
                            <input type="checkbox" name="remove_cover" value="1" class="size-4" @checked(old('remove_cover'))>
                            Remove current cover
                        </label>
                    @endif
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="text-sm font-semibold text-slate-950">Preview</div>
                    <div
                        class="mt-3 max-h-[480px] overflow-auto border-t border-slate-200 pt-3 text-sm leading-6 text-slate-700 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mt-5 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:mt-1 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5"
                        x-html="preview"
                    >{!! $previewHtml !!}</div>
                </div>
            </aside>
        </div>
    </form>
</x-layouts.dashboard>

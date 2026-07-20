<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardBlogController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('cms.manage-blog');

        return Inertia::render('dashboard/Blog', [
            'posts' => BlogPost::query()
                ->with('author:id,name')
                ->latest()
                ->get()
                ->map(fn (BlogPost $post): array => $post->toDashboardArray()),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('cms.manage-blog');

        return Inertia::render('dashboard/BlogForm', [
            'post' => null,
            'previewHtml' => '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('cms.manage-blog');

        $validated = $this->validatedPost($request);
        $validated['author_id'] = $request->user()?->id;

        if ($request->hasFile('cover')) {
            $validated['cover_path'] = $request->file('cover')?->store('blog-covers', 'public');
        }

        BlogPost::query()->create($validated);

        return redirect()->route('dashboard.blog.index')->with('success', 'Post saved.');
    }

    public function edit(BlogPost $post): Response
    {
        Gate::authorize('cms.manage-blog');

        return Inertia::render('dashboard/BlogForm', [
            'post' => $post->load('author:id,name')->toDashboardArray(),
            'previewHtml' => BlogPost::renderMarkdown($post->body_markdown),
        ]);
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        Gate::authorize('cms.manage-blog');

        $validated = $this->validatedPost($request, $post);

        if ($request->boolean('remove_cover') && $post->cover_path) {
            Storage::disk('public')->delete($post->cover_path);
            $validated['cover_path'] = null;
        }

        if ($request->hasFile('cover')) {
            if ($post->cover_path) {
                Storage::disk('public')->delete($post->cover_path);
            }

            $validated['cover_path'] = $request->file('cover')?->store('blog-covers', 'public');
        }

        $post->update($validated);

        return redirect()->route('dashboard.blog.index')->with('success', 'Post saved.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        Gate::authorize('cms.manage-blog');

        $post->delete();

        return back()->with('success', 'Post deleted.');
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        Gate::authorize('cms.manage-blog');

        $post->update($post->status === 'published'
            ? ['status' => 'draft', 'published_at' => null]
            : ['status' => 'published', 'published_at' => $post->published_at ?? now()]
        );

        return back()->with('success', 'Publish status updated.');
    }

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('cms.manage-blog');

        $validated = $request->validate([
            'body_markdown' => ['nullable', 'string'],
        ]);

        return response()->json([
            'html' => BlogPost::renderMarkdown((string) ($validated['body_markdown'] ?? '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPost(Request $request, ?BlogPost $post = null): array
    {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title'))),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:180', Rule::unique(BlogPost::class, 'slug')->ignore($post?->id)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body_markdown' => ['required', 'string'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_cover' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => ['nullable', 'date'],
        ]);

        unset($validated['cover'], $validated['remove_cover']);

        if ($validated['status'] === 'published') {
            $validated['published_at'] = $validated['published_at'] ?? now();
        } else {
            $validated['published_at'] = null;
        }

        return $validated;
    }
}

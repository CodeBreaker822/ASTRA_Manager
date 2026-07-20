<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/BlogIndex', [
            'posts' => BlogPost::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->get()
                ->map(fn (BlogPost $post): array => $post->toPublicArray())
                ->values(),
        ]);
    }

    public function show(string $slug): Response
    {
        $post = BlogPost::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('slug', $slug)
            ->first();

        if (! $post) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('marketing/BlogShow', [
            'post' => $post->toPublicArray(withBody: true),
        ]);
    }
}

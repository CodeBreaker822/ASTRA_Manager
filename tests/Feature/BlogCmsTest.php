<?php

use App\Models\BlogPost;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('public blog renders published database posts with sanitized markdown', function () {
    $this->withoutVite();

    $published = BlogPost::factory()->published()->create([
        'title' => 'Public CMS Post',
        'slug' => 'public-cms-post',
        'body_markdown' => "## Safe heading\n\n<script>alert('xss')</script>\n\n[bad](javascript:alert(1))",
    ]);
    $draft = BlogPost::factory()->create([
        'title' => 'Draft CMS Post',
        'slug' => 'draft-cms-post',
    ]);

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/BlogIndex')
            ->has('posts', 1)
            ->where('posts.0.slug', $published->slug)
        );

    $this->get(route('blog.show', $published->slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/BlogShow')
            ->where('post.slug', $published->slug)
            ->where('post.html', fn (string $html): bool => str_contains($html, '<h2>Safe heading</h2>')
                && ! str_contains($html, '<script')
                && ! str_contains($html, 'javascript:alert'))
        );

    $this->get(route('blog.show', $draft->slug))
        ->assertNotFound();
});

test('content editors can create publish update and delete blog posts', function () {
    $this->withoutVite();
    Storage::fake('public');

    $user = createBlogManagerUser();

    $this->actingAs($user)
        ->post(route('dashboard.blog.store'), [
            'title' => 'A CMS Shipped Post',
            'slug' => '',
            'excerpt' => 'Editable public content.',
            'body_markdown' => '## Stored from the dashboard',
            'status' => 'draft',
            'published_at' => '',
            'cover' => UploadedFile::fake()->create('cover.jpg', 120, 'image/jpeg'),
        ])
        ->assertRedirect(route('dashboard.blog.index', absolute: false));

    $post = BlogPost::query()->where('slug', 'a-cms-shipped-post')->firstOrFail();

    expect($post->status)->toBe('draft')
        ->and($post->cover_path)->not->toBeNull();
    Storage::disk('public')->assertExists($post->cover_path);

    $this->actingAs($user)
        ->get(route('dashboard.blog.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Blog')
            ->has('posts', 1)
            ->where('posts.0.slug', 'a-cms-shipped-post')
        );

    $this->actingAs($user)
        ->post(route('dashboard.blog.publish', $post))
        ->assertRedirect();

    expect($post->refresh()->status)->toBe('published')
        ->and($post->published_at)->not->toBeNull();

    $this->actingAs($user)
        ->put(route('dashboard.blog.update', $post), [
            'title' => 'A CMS Updated Post',
            'slug' => 'a-cms-updated-post',
            'excerpt' => 'Updated in the dashboard.',
            'body_markdown' => '## Updated dashboard body',
            'status' => 'published',
            'published_at' => $post->published_at?->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('dashboard.blog.index', absolute: false));

    expect($post->refresh()->title)->toBe('A CMS Updated Post')
        ->and($post->slug)->toBe('a-cms-updated-post');

    $this->actingAs($user)
        ->delete(route('dashboard.blog.destroy', $post))
        ->assertRedirect();

    expect(BlogPost::query()->whereKey($post->id)->exists())->toBeFalse();
});

test('blog dashboard routes require the blog management gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.blog.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('dashboard.blog.store'), [
            'title' => 'Denied',
            'body_markdown' => 'Denied',
            'status' => 'draft',
        ])
        ->assertForbidden();
});

test('markdown preview is sanitized and gate protected', function () {
    $user = createBlogManagerUser();

    $this->actingAs($user)
        ->postJson(route('dashboard.blog.preview'), [
            'body_markdown' => "<script>alert('xss')</script>\n\n[bad](javascript:alert(1))",
        ])
        ->assertOk()
        ->assertJsonMissingExact(['html' => "<script>alert('xss')</script>"])
        ->assertJson(fn ($json) => $json
            ->where('html', fn (string $html): bool => ! str_contains($html, '<script')
                && ! str_contains($html, 'javascript:alert'))
        );

    $this->actingAs(User::factory()->create())
        ->postJson(route('dashboard.blog.preview'), [
            'body_markdown' => '# Denied',
        ])
        ->assertForbidden();
});

function createBlogManagerUser(): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_BLOG_MANAGER',
        'position_name' => 'Test Blog Manager',
        'assigned_office' => 'web',
        'category' => 'cms',
        'description' => 'Test blog manager position',
        'is_active' => true,
    ]);

    foreach (['cms.view', 'cms.manage-blog'] as $permission) {
        UserPermissions::query()->create([
            'position_id' => $position->id,
            'permission_name' => $permission,
        ]);
    }

    return User::factory()->create([
        'position_id' => $position->id,
    ]);
}

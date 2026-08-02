<?php

use App\Models\PageContent;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Database\Seeders\PageContentSeeder;
use Database\Seeders\PlanTierSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::flush();
});

test('the production page seeder creates every CMS page and section', function () {
    $this->seed(PageContentSeeder::class);

    foreach (config('marketing.pages') as $page => $sections) {
        expect(PageContent::query()->where('page', $page)->count())
            ->toBe(count($sections));

        foreach (array_keys($sections) as $section) {
            expect(PageContent::query()
                ->where('page', $page)
                ->where('section', $section)
                ->exists())->toBeTrue();
        }
    }
});

test('page managers can edit every public page and shared site content', function () {
    $this->withoutVite();
    $this->seed([PageContentSeeder::class, PlanTierSeeder::class]);

    $manager = createPageManagerUser();
    $updates = [
        'site' => ['brand.name', 'Managed Transcriber'],
        'home' => ['hero.title', 'Managed home audio to text'],
        'audio_to_text' => ['hero.title', 'Managed audio-to-text page'],
        'features' => ['hero.title', 'Managed feature page'],
        'pricing' => ['hero.title', 'Managed pricing page'],
        'download' => ['download_card.button_label', 'Managed Windows download'],
        'blog' => ['hero.title', 'Managed transcription guides'],
    ];

    foreach ($updates as $page => [$path, $value]) {
        $content = config("marketing.pages.{$page}");
        data_set($content, $path, $value);

        if ($page === 'site') {
            data_set($content, 'seo.site_name', 'Managed Site Name');
        }

        $this->actingAs($manager)
            ->put(route('dashboard.pages.update', ['page' => $page]), [
                'content' => $content,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Landing')
            ->where('content.hero.title', 'Managed home audio to text')
            ->where('marketingSite.brand.name', 'Managed Transcriber')
            ->where('seo.site_name', 'Managed Site Name')
        );

    $this->get(route('audio-to-text'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/AudioToText')
            ->where('content.hero.title', 'Managed audio-to-text page')
        );

    $this->get(route('features'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Features')
            ->where('content.hero.title', 'Managed feature page')
        );

    $this->get(route('price'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Price')
            ->where('content.hero.title', 'Managed pricing page')
        );

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Download')
            ->where('content.download_card.button_label', 'Managed Windows download')
        );

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/BlogIndex')
            ->where('content.hero.title', 'Managed transcription guides')
        );
});

test('the unified page editor exposes every page schema and preview', function () {
    $this->withoutVite();
    $manager = createPageManagerUser();

    $this->actingAs($manager)
        ->get(route('dashboard.pages.edit', ['page' => 'audio_to_text']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Pages')
            ->where('pageKey', 'audio_to_text')
            ->where('previewUrl', '/audio-to-text')
            ->has('pageOptions', 7)
            ->has('schema.hero')
            ->has('schema.guides.items')
            ->has('content.faq')
        );
});

test('page updates reject unknown fields and unsafe links', function () {
    $manager = createPageManagerUser();
    $content = config('marketing.pages.site');
    $content['navigation']['primary_button_url'] = 'javascript:alert(1)';
    $content['navigation']['unexpected_copy'] = 'Not in the CMS schema';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.update', ['page' => 'site']), [
            'content' => $content,
        ])
        ->assertSessionHasErrors([
            'content.navigation',
            'content.navigation.primary_button_url',
        ]);
});

test('rerunning the page seeder preserves edits and only restores missing fields', function () {
    $this->seed(PageContentSeeder::class);

    $hero = PageContent::query()
        ->where('page', 'home')
        ->where('section', 'hero')
        ->firstOrFail();
    $content = $hero->content;
    $content['title'] = 'Keep this dashboard edit';
    unset($content['note']);
    $hero->update(['content' => $content]);

    $this->seed(PageContentSeeder::class);

    $stored = $hero->refresh()->content;

    expect($stored['title'])->toBe('Keep this dashboard edit')
        ->and($stored['note'])->toBe(config('marketing.pages.home.hero.note'));
});

test('page dashboard routes require the pages management gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.pages.edit', ['page' => 'home']))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('dashboard.pages.update', ['page' => 'download']), [
            'content' => config('marketing.pages.download'),
        ])
        ->assertForbidden();
});

function createPageManagerUser(): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_PAGE_MANAGER',
        'position_name' => 'Test Page Manager',
        'assigned_office' => 'web',
        'category' => 'cms',
        'description' => 'Test page manager position',
        'is_active' => true,
    ]);

    foreach (['cms.view', 'cms.manage-pages'] as $permission) {
        UserPermissions::query()->create([
            'position_id' => $position->id,
            'permission_name' => $permission,
        ]);
    }

    return User::factory()->create([
        'position_id' => $position->id,
    ]);
}

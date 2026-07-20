<?php

use App\Models\PageContent;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::flush();
});

test('features and download pages use marketing config fallback', function () {
    $this->withoutVite();

    $this->get(route('features'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Features')
            ->where('content.hero.title', 'Online transcription, shaped like the desktop workspace.')
            ->where('content.feature_rows.0.icon', 'Mic')
        );

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Download')
            ->where('content.hero.title', 'Get JERVA for desktop')
            ->where('content.requirements.0.icon', 'Laptop')
        );
});

test('page managers can update features and download content', function () {
    $this->withoutVite();

    $manager = createPageManagerUser();

    $features = config('marketing.pages.features');
    $features['hero']['title'] = 'Editable feature story';
    $features['feature_rows'][0]['title'] = 'Managed live transcription';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.features.update'), ['content' => $features])
        ->assertRedirect();

    expect(PageContent::query()->where('page', 'features')->where('section', 'hero')->firstOrFail()->content['title'])
        ->toBe('Editable feature story');

    $this->get(route('features'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Features')
            ->where('content.hero.title', 'Editable feature story')
            ->where('content.feature_rows.0.title', 'Managed live transcription')
        );

    $download = config('marketing.pages.download');
    $download['download_card']['button_label'] = 'Download managed build';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.download.update'), ['content' => $download])
        ->assertRedirect();

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Download')
            ->where('content.download_card.button_label', 'Download managed build')
        );
});

test('page dashboard routes require the pages management gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.pages.features.edit'))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('dashboard.pages.download.update'), [
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

<?php

use App\Models\PageContent;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('marketing pages use source defaults when CMS content is missing', function () {
    $this->withoutVite();

    PageContent::query()->delete();

    $response = $this->get(route('home'))->assertOk();
    expectViewPath($response, 'content.hero.title')
        ->toBe(config('marketing.pages.home.hero.title'));

    $this->get(route('features'))
        ->assertOk();

    $this->get(route('download'))
        ->assertOk();
});

test('page managers can update home features and download content', function () {
    $this->withoutVite();

    $manager = createPageManagerUser();

    $home = config('marketing.pages.home');
    $home['hero']['title'] = 'Editable home story';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.update', ['page' => 'home']), ['content' => $home])
        ->assertRedirect();

    $response = $this->get(route('home'))
        ->assertOk()
        ->assertViewIs('marketing.landing');

    expectViewPath($response, 'content.hero.title')->toBe('Editable home story');
    expectViewPath($response, 'seo.title')->toBe($home['seo']['title']);

    $features = config('marketing.pages.features');
    $features['hero']['title'] = 'Editable feature story';
    $features['feature_rows'][0]['title'] = 'Managed live transcription';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.update', ['page' => 'features']), ['content' => $features])
        ->assertRedirect();

    expect(PageContent::query()->where('page', 'features')->where('section', 'hero')->firstOrFail()->content['title'])
        ->toBe('Editable feature story');

    $featuresPage = $this->get(route('features'))
        ->assertOk()
        ->assertViewIs('marketing.features');

    expectViewPath($featuresPage, 'content.hero.title')->toBe('Editable feature story');
    expectViewPath($featuresPage, 'content.feature_rows.0.title')->toBe('Managed live transcription');

    $download = config('marketing.pages.download');
    $download['download_card']['button_label'] = 'Download managed build';

    $this->actingAs($manager)
        ->put(route('dashboard.pages.update', ['page' => 'download']), ['content' => $download])
        ->assertRedirect();

    $downloadPage = $this->get(route('download'))
        ->assertOk()
        ->assertViewIs('marketing.download');

    expectViewPath($downloadPage, 'content.download_card.button_label')
        ->toBe('Download managed build');
});

test('page dashboard routes require the pages management gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.pages.edit', ['page' => 'home']))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('dashboard.pages.edit', ['page' => 'features']))
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

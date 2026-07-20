<?php

namespace App\Http\Controllers;

use App\Services\PageContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardPageController extends Controller
{
    public function features(PageContentService $pages): Response
    {
        Gate::authorize('cms.manage-pages');

        return $this->edit('features', 'Features', $pages);
    }

    public function download(PageContentService $pages): Response
    {
        Gate::authorize('cms.manage-pages');

        return $this->edit('download', 'Download', $pages);
    }

    public function updateFeatures(Request $request, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pages');

        $validated = $request->validate([
            'content.hero.eyebrow' => ['required', 'string', 'max:80'],
            'content.hero.title' => ['required', 'string', 'max:180'],
            'content.hero.intro' => ['required', 'string', 'max:500'],
            'content.feature_rows' => ['required', 'array', 'min:1', 'max:6'],
            'content.feature_rows.*.eyebrow' => ['required', 'string', 'max:80'],
            'content.feature_rows.*.icon' => ['required', 'string', Rule::in(['Mic', 'FileAudio', 'Languages', 'Sparkles', 'FileSpreadsheet', 'Network'])],
            'content.feature_rows.*.title' => ['required', 'string', 'max:180'],
            'content.feature_rows.*.body' => ['required', 'string', 'max:500'],
            'content.feature_rows.*.bullets' => ['array', 'max:3'],
            'content.feature_rows.*.bullets.*' => ['nullable', 'string', 'max:120'],
            'content.cta.title' => ['required', 'string', 'max:180'],
            'content.cta.body' => ['required', 'string', 'max:300'],
            'content.cta.button_label' => ['required', 'string', 'max:80'],
        ]);

        $content = $validated['content'];
        $content['feature_rows'] = array_map(fn (array $row): array => [
            ...$row,
            'bullets' => array_values(array_filter($row['bullets'] ?? [], fn (?string $bullet): bool => filled($bullet))),
        ], $content['feature_rows']);

        $pages->save('features', $content, $request->user()?->id);

        return back()->with('success', 'Features page saved.');
    }

    public function updateDownload(Request $request, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pages');

        $validated = $request->validate([
            'content.hero.eyebrow' => ['required', 'string', 'max:80'],
            'content.hero.title' => ['required', 'string', 'max:180'],
            'content.hero.intro' => ['required', 'string', 'max:500'],
            'content.download_card.title' => ['required', 'string', 'max:120'],
            'content.download_card.body' => ['required', 'string', 'max:300'],
            'content.download_card.button_label' => ['required', 'string', 'max:80'],
            'content.download_card.empty_label' => ['required', 'string', 'max:80'],
            'content.requirements' => ['required', 'array', 'min:1', 'max:4'],
            'content.requirements.*.icon' => ['required', 'string', Rule::in(['Laptop', 'Cpu', 'HardDrive', 'ShieldCheck'])],
            'content.requirements.*.title' => ['required', 'string', 'max:80'],
            'content.requirements.*.body' => ['required', 'string', 'max:300'],
            'content.account.title' => ['required', 'string', 'max:180'],
            'content.account.body' => ['required', 'string', 'max:400'],
            'content.account.bullets' => ['array', 'max:4'],
            'content.account.bullets.*' => ['nullable', 'string', 'max:140'],
            'content.account.button_label' => ['required', 'string', 'max:80'],
            'content.faq' => ['array', 'max:6'],
            'content.faq.*.question' => ['required', 'string', 'max:180'],
            'content.faq.*.answer' => ['required', 'string', 'max:500'],
        ]);

        $content = $validated['content'];
        $content['account']['bullets'] = array_values(array_filter($content['account']['bullets'] ?? [], fn (?string $bullet): bool => filled($bullet)));

        $pages->save('download', $content, $request->user()?->id);

        return back()->with('success', 'Download page saved.');
    }

    private function edit(string $page, string $title, PageContentService $pages): Response
    {
        return Inertia::render('dashboard/Pages', [
            'pageKey' => $page,
            'title' => $title,
            'content' => $pages->page($page),
        ]);
    }
}

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
    public function home(PageContentService $pages): Response
    {
        Gate::authorize('cms.manage-pages');

        return $this->edit('home', 'Home', $pages);
    }

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

    public function updateHome(Request $request, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pages');

        $validated = $request->validate([
            ...$this->seoRules(),
            ...$this->heroRules([
                'content.hero.online_button_label' => ['required', 'string', 'max:80'],
                'content.hero.desktop_button_label' => ['required', 'string', 'max:80'],
            ]),
            'content.paths' => ['required', 'array', 'size:2'],
            'content.paths.*.eyebrow' => ['required', 'string', 'max:80'],
            'content.paths.*.title' => ['required', 'string', 'max:180'],
            'content.paths.*.body' => ['required', 'string', 'max:600'],
            'content.paths.*.bullets' => ['required', 'array', 'min:1', 'max:4'],
            'content.paths.*.bullets.*' => ['required', 'string', 'max:140'],
            'content.paths.*.button_label' => ['required', 'string', 'max:80'],
            'content.paths.*.button_url' => ['required', 'string', 'max:200', 'starts_with:/'],
            'content.workflow.title' => ['required', 'string', 'max:180'],
            'content.workflow.intro' => ['required', 'string', 'max:500'],
            'content.workflow.steps' => ['required', 'array', 'size:3'],
            'content.workflow.steps.*.title' => ['required', 'string', 'max:120'],
            'content.workflow.steps.*.body' => ['required', 'string', 'max:400'],
            'content.use_cases.title' => ['required', 'string', 'max:180'],
            'content.use_cases.intro' => ['required', 'string', 'max:500'],
            'content.use_cases.items' => ['required', 'array', 'size:3'],
            'content.use_cases.items.*.title' => ['required', 'string', 'max:120'],
            'content.use_cases.items.*.body' => ['required', 'string', 'max:400'],
            'content.vad.eyebrow' => ['required', 'string', 'max:80'],
            'content.vad.title' => ['required', 'string', 'max:180'],
            'content.vad.body' => ['required', 'string', 'max:600'],
            'content.vad.note' => ['required', 'string', 'max:300'],
            ...$this->faqRules(),
            'content.cta.title' => ['required', 'string', 'max:180'],
            'content.cta.body' => ['required', 'string', 'max:400'],
            'content.cta.online_button_label' => ['required', 'string', 'max:80'],
            'content.cta.desktop_button_label' => ['required', 'string', 'max:80'],
        ]);

        $pages->save('home', $validated['content'], $request->user()?->id);

        return back()->with('success', 'Home page saved.');
    }

    public function updateFeatures(Request $request, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pages');

        $validated = $request->validate([
            ...$this->seoRules(),
            ...$this->heroRules(),
            'content.feature_rows' => ['required', 'array', 'min:1', 'max:6'],
            'content.feature_rows.*.eyebrow' => ['required', 'string', 'max:80'],
            'content.feature_rows.*.icon' => ['required', 'string', Rule::in(['Mic', 'FileAudio', 'Languages', 'Sparkles', 'FileSpreadsheet', 'Network'])],
            'content.feature_rows.*.title' => ['required', 'string', 'max:180'],
            'content.feature_rows.*.body' => ['required', 'string', 'max:500'],
            'content.feature_rows.*.bullets' => ['array', 'max:3'],
            'content.feature_rows.*.bullets.*' => ['nullable', 'string', 'max:120'],
            'content.comparison.title' => ['required', 'string', 'max:180'],
            'content.comparison.intro' => ['required', 'string', 'max:500'],
            'content.comparison.rows' => ['required', 'array', 'min:1', 'max:8'],
            'content.comparison.rows.*.label' => ['required', 'string', 'max:100'],
            'content.comparison.rows.*.online' => ['required', 'string', 'max:240'],
            'content.comparison.rows.*.desktop' => ['required', 'string', 'max:240'],
            ...$this->faqRules(),
            'content.cta.title' => ['required', 'string', 'max:180'],
            'content.cta.body' => ['required', 'string', 'max:400'],
            'content.cta.online_button_label' => ['required', 'string', 'max:80'],
            'content.cta.desktop_button_label' => ['required', 'string', 'max:80'],
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
            ...$this->seoRules(),
            ...$this->heroRules(),
            'content.download_card.title' => ['required', 'string', 'max:120'],
            'content.download_card.body' => ['required', 'string', 'max:300'],
            'content.download_card.button_label' => ['required', 'string', 'max:80'],
            'content.download_card.empty_label' => ['required', 'string', 'max:80'],
            'content.benefits' => ['required', 'array', 'min:1', 'max:4'],
            'content.benefits.*.icon' => ['required', 'string', Rule::in(['ShieldCheck', 'Mic', 'Scissors', 'Users'])],
            'content.benefits.*.title' => ['required', 'string', 'max:100'],
            'content.benefits.*.body' => ['required', 'string', 'max:350'],
            'content.models.title' => ['required', 'string', 'max:180'],
            'content.models.intro' => ['required', 'string', 'max:500'],
            'content.models.items' => ['required', 'array', 'size:5'],
            'content.models.items.*.name' => ['required', 'string', 'max:80'],
            'content.models.items.*.size' => ['required', 'string', 'max:40'],
            'content.models.items.*.best_for' => ['required', 'string', 'max:240'],
            'content.models.note' => ['required', 'string', 'max:300'],
            'content.requirements' => ['required', 'array', 'min:1', 'max:4'],
            'content.requirements.*.icon' => ['required', 'string', Rule::in(['Laptop', 'Cpu', 'HardDrive', 'ShieldCheck'])],
            'content.requirements.*.title' => ['required', 'string', 'max:80'],
            'content.requirements.*.body' => ['required', 'string', 'max:300'],
            'content.steps.title' => ['required', 'string', 'max:180'],
            'content.steps.items' => ['required', 'array', 'size:3'],
            'content.steps.items.*.title' => ['required', 'string', 'max:100'],
            'content.steps.items.*.body' => ['required', 'string', 'max:350'],
            'content.account.title' => ['required', 'string', 'max:180'],
            'content.account.body' => ['required', 'string', 'max:400'],
            'content.account.bullets' => ['array', 'max:4'],
            'content.account.bullets.*' => ['nullable', 'string', 'max:140'],
            'content.account.button_label' => ['required', 'string', 'max:80'],
            ...$this->faqRules(),
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

    /**
     * @return array<string, array<int, mixed>>
     */
    private function seoRules(): array
    {
        return [
            'content.seo.title' => ['required', 'string', 'max:80'],
            'content.seo.description' => ['required', 'string', 'max:180'],
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $extra
     * @return array<string, array<int, mixed>>
     */
    private function heroRules(array $extra = []): array
    {
        return [
            'content.hero.eyebrow' => ['required', 'string', 'max:120'],
            'content.hero.title' => ['required', 'string', 'max:180'],
            'content.hero.intro' => ['required', 'string', 'max:600'],
            ...$extra,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function faqRules(): array
    {
        return [
            'content.faq' => ['required', 'array', 'min:1', 'max:8'],
            'content.faq.*.question' => ['required', 'string', 'max:180'],
            'content.faq.*.answer' => ['required', 'string', 'max:700'],
        ];
    }
}

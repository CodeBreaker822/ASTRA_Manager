<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TranscriptProject;
use App\Services\Billing\EntitlementService;
use App\Services\Web\WorkspacePayloadPresenter;
use App\Support\Money;
use App\Support\WorkspaceView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request, EntitlementService $entitlements, WorkspacePayloadPresenter $presenter): View
    {
        return $this->renderWorkspace($request, $entitlements, $presenter);
    }

    public function show(
        Request $request,
        EntitlementService $entitlements,
        WorkspacePayloadPresenter $presenter,
        TranscriptProject $project,
    ): View {
        $this->authorize('view', $project);

        return $this->renderWorkspace($request, $entitlements, $presenter, $project);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        $project = $request->user()->transcriptProjects()->create([
            'title' => $validated['title'],
        ]);

        return redirect()->route('workspace.show', $project)
            ->with('toast', ['type' => 'success', 'message' => 'Transcript project created.']);
    }

    public function update(Request $request, TranscriptProject $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        $project->update(['title' => $validated['title']]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Transcript project renamed.']);
    }

    public function destroy(Request $request, TranscriptProject $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()->route('workspace.index')
            ->with('toast', ['type' => 'success', 'message' => 'Transcript project deleted.']);
    }

    private function renderWorkspace(
        Request $request,
        EntitlementService $entitlements,
        WorkspacePayloadPresenter $presenter,
        ?TranscriptProject $activeProject = null,
    ): View {
        $projects = $request->user()
            ->transcriptProjects()
            ->withCount('transcripts')
            ->latest('updated_at')
            ->get()
            ->map(fn (TranscriptProject $project): array => $presenter->projectSummary($project))
            ->all();

        $project = $activeProject ? $presenter->activeProject($activeProject) : null;
        $mode = WorkspaceView::mode($project);
        $summary = $entitlements->summaryFor($request->user());
        $primaryTranscript = WorkspaceView::primaryTranscript($project, $mode);
        $features = $summary['plan']['features'] ?? [];

        return view('workspace.index', [
            ...WorkspaceView::transcriptPane($project, $mode),
            'projects' => $projects,
            'project' => $project,
            'user' => $request->user(),
            'entitlements' => $summary,
            'mode' => $mode,
            'title' => WorkspaceView::title($project, $mode),
            'primaryTranscript' => $primaryTranscript,
            'hasPendingWork' => WorkspaceView::hasPendingWork($project),
            'showActions' => WorkspaceView::showActions($project, $mode),
            'hasRaw' => WorkspaceView::hasRawText($primaryTranscript),
            'summaryText' => (string) ($primaryTranscript['summary_text'] ?? ''),
            'actions' => WorkspaceView::actionState($primaryTranscript),
            'creditBalance' => Money::format($summary['usage']['wallet_balance_cents'] / 100, 'USD', 2, 2),
            'canUseUpload' => (bool) ($features['upload'] ?? false) && $project !== null,
            'canUseLive' => (bool) ($features['live'] ?? false) && $project !== null,
        ]);
    }
}

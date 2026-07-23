<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\Web\WorkspacePayloadPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request, EntitlementService $entitlements, WorkspacePayloadPresenter $presenter): Response
    {
        return $this->renderWorkspace($request, $entitlements, $presenter);
    }

    public function show(
        Request $request,
        EntitlementService $entitlements,
        WorkspacePayloadPresenter $presenter,
        TranscriptProject $project,
    ): Response {
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
    ): Response {
        $projects = $request->user()
            ->transcriptProjects()
            ->withCount('transcripts')
            ->latest('updated_at')
            ->get()
            ->map(fn (TranscriptProject $project): array => $presenter->projectSummary($project))
            ->all();

        return Inertia::render('workspace/Index', [
            'projects' => $projects,
            'activeProject' => $activeProject ? $presenter->activeProject($activeProject) : null,
            'entitlements' => $entitlements->summaryFor($request->user()),
        ]);
    }
}

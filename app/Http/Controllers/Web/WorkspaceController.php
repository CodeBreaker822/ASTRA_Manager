<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\TranscriptSection;
use App\Services\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request, EntitlementService $entitlements): Response
    {
        return $this->renderWorkspace($request, $entitlements);
    }

    public function show(Request $request, EntitlementService $entitlements, TranscriptProject $project): Response
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        return $this->renderWorkspace($request, $entitlements, $project);
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
            ->with('success', 'Transcript project created.');
    }

    public function update(Request $request, TranscriptProject $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        $project->update(['title' => $validated['title']]);

        return back()->with('success', 'Transcript project renamed.');
    }

    public function destroy(Request $request, TranscriptProject $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        $project->delete();

        return redirect()->route('workspace.index')
            ->with('success', 'Transcript project deleted.');
    }

    private function renderWorkspace(
        Request $request,
        EntitlementService $entitlements,
        ?TranscriptProject $activeProject = null,
    ): Response {
        $projects = $request->user()
            ->transcriptProjects()
            ->withCount('transcripts')
            ->latest('updated_at')
            ->get()
            ->map(fn (TranscriptProject $project): array => [
                'id' => $project->id,
                'title' => $project->title,
                'updated_at' => $project->updated_at?->diffForHumans(),
                'transcripts_count' => (int) $project->getAttribute('transcripts_count'),
            ])
            ->all();

        $activeProject?->load(['transcripts.sections' => fn ($query) => $query->orderBy('position')]);

        return Inertia::render('workspace/Index', [
            'projects' => $projects,
            'activeProject' => $activeProject ? [
                'id' => $activeProject->id,
                'title' => $activeProject->title,
                'updated_at' => $activeProject->updated_at?->diffForHumans(),
                'transcripts' => $activeProject->transcripts
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn (Transcript $transcript): array => [
                        'id' => $transcript->id,
                        'source' => $transcript->source,
                        'status' => $transcript->status,
                        'duration_seconds' => $transcript->duration_seconds,
                        'raw_text' => $transcript->raw_text,
                        'cleaned_text' => $transcript->cleaned_text,
                        'summary_text' => $transcript->summary_text,
                        'processing_log' => $transcript->processing_log ?? [],
                        'sections' => $transcript->sections
                            ->map(fn (TranscriptSection $section): array => [
                                'id' => $section->id,
                                'position' => $section->position,
                                'text' => $section->text,
                                'cleaned_text' => $section->cleaned_text,
                                'started_at_ms' => $section->started_at_ms,
                                'ended_at_ms' => $section->ended_at_ms,
                            ])
                            ->all(),
                    ])
                    ->all(),
            ] : null,
            'entitlements' => $entitlements->summaryFor($request->user()),
        ]);
    }
}

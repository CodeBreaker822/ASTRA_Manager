<?php

namespace App\Services\Web;

use App\Models\TranscriptProject;

class WorkspacePayloadPresenter
{
    public function __construct(private readonly TranscriptPayloadPresenter $transcripts) {}

    /**
     * @return array<string, mixed>
     */
    public function projectSummary(TranscriptProject $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'updated_at' => $project->updated_at?->diffForHumans(),
            'transcripts_count' => (int) $project->getAttribute('transcripts_count'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activeProject(TranscriptProject $project): array
    {
        $project->loadMissing(['transcripts.sections' => fn ($query) => $query->orderBy('position')]);

        return [
            'id' => $project->id,
            'title' => $project->title,
            'updated_at' => $project->updated_at?->diffForHumans(),
            'transcripts' => $project->transcripts
                ->sortByDesc('created_at')
                ->values()
                ->map(fn ($transcript): array => $this->transcripts->present($transcript))
                ->all(),
        ];
    }
}

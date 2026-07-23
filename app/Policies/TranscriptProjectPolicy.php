<?php

namespace App\Policies;

use App\Models\TranscriptProject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TranscriptProjectPolicy
{
    public function view(User $user, TranscriptProject $project): Response
    {
        return $this->owns($user, $project);
    }

    public function update(User $user, TranscriptProject $project): Response
    {
        return $this->owns($user, $project);
    }

    public function delete(User $user, TranscriptProject $project): Response
    {
        return $this->owns($user, $project);
    }

    private function owns(User $user, TranscriptProject $project): Response
    {
        return (int) $project->user_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}

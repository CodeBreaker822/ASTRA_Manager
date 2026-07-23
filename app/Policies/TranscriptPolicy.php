<?php

namespace App\Policies;

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TranscriptPolicy
{
    public function view(User $user, Transcript $transcript): Response
    {
        return $this->owns($user, $transcript);
    }

    public function update(User $user, Transcript $transcript): Response
    {
        return $this->owns($user, $transcript);
    }

    public function delete(User $user, Transcript $transcript): Response
    {
        return $this->owns($user, $transcript);
    }

    private function owns(User $user, Transcript $transcript): Response
    {
        $transcript->loadMissing('project');

        return (int) $transcript->project?->user_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}

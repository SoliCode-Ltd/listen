<?php

namespace App\Policies;

use App\Models\ClonedVoice;
use App\Models\User;

/**
 * Authorization policy for ClonedVoice model.
 *
 * Enforces that users can only manage their own cloned voices.
 */
class ClonedVoicePolicy
{
    /**
     * User can view their own cloned voice.
     */
    public function view(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id;
    }

    /**
     * User can delete their own cloned voice.
     */
    public function delete(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id;
    }

    /**
     * User can use their own cloned voice for generation.
     */
    public function use(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id && $clonedVoice->isReady();
    }
}

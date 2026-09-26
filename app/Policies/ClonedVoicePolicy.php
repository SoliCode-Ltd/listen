<?php

namespace App\Policies;

use App\Models\ClonedVoice;
use App\Models\User;
use App\Services\VoiceCloningService;

/**
 * Authorization policy for ClonedVoice model.
 *
 * Enforces that users can only manage their own cloned voices.
 */
class ClonedVoicePolicy
{
    /**
     * Filament admins can inspect and delete every cloned voice. Using a
     * voice for generation stays owner-only.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($ability !== 'use' && $user->is_admin === true && ! $user->isBlocked()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * User can view their own cloned voice.
     */
    public function view(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id;
    }

    /**
     * A finished clone stays on the account so it keeps its plan slot.
     * Voices that never succeeded can still be removed.
     */
    public function delete(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id
            && VoiceCloningService::canUserDelete($clonedVoice);
    }

    /**
     * User can use their own cloned voice for generation.
     */
    public function use(User $user, ClonedVoice $clonedVoice): bool
    {
        return $user->id === $clonedVoice->user_id && $clonedVoice->isReady();
    }
}

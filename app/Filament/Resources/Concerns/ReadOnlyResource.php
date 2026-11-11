<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource the admin only reads: nothing can be added, changed, deleted,
 * restored or copied from it (the Agents section: its agents, calls and
 * customers belong to the members who made them).
 */
trait ReadOnlyResource
{
    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canEdit() alike): never allowed here.
    public static function getEditAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canDelete() alike): never allowed here.
    public static function getDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canDeleteAny() alike): never allowed here.
    public static function getDeleteAnyAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canForceDelete() alike): never allowed here.
    public static function getForceDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canForceDeleteAny() alike): never allowed here.
    public static function getForceDeleteAnyAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canRestore() alike): never allowed here.
    public static function getRestoreAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canRestoreAny() alike): never allowed here.
    public static function getRestoreAnyAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canReplicate() alike): never allowed here.
    public static function getReplicateAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canReorder() alike): never allowed here.
    public static function getReorderAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }
}

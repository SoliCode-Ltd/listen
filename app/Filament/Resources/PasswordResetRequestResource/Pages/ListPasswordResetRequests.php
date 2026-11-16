<?php

namespace App\Filament\Resources\PasswordResetRequestResource\Pages;

use App\Filament\Concerns\SimplePages;
use App\Filament\Resources\PasswordResetRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPasswordResetRequests extends ListRecords
{
    use SimplePages;

    protected static string $resource = PasswordResetRequestResource::class;

    protected ?string $subheading = 'Everyone who asked for a password reset link on the sign-in page, and whether they changed their password.';
}

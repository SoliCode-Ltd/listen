<?php

namespace App\Filament\Resources;

use App\Models\SavedPaymentMethod;
use Filament\Resources\Resource;

/**
 * The old "Saved cards" page, replaced by Billing → Payments. The servers get
 * files by upload and never have one deleted, so this file stays to take the old
 * page's place: it has no pages, no menu entry and no access. The cards on file
 * stay kept (SavedPaymentMethod), as trials and renewals charge them.
 */
class SavedPaymentMethodResource extends Resource
{
    protected static ?string $model = SavedPaymentMethod::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [];
    }
}

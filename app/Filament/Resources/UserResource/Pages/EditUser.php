<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\UserDeletionService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // A member who would still be charged is kept, with the reason.
            Actions\DeleteAction::make()
                ->action(function (Actions\DeleteAction $action, User $record): void {
                    $refusal = app(UserDeletionService::class)->delete($record, Auth::user());
                    if ($refusal !== null) {
                        Notification::make()
                            ->danger()
                            ->title(__('ui.admin.users.delete_refused_title'))
                            ->body($refusal)
                            ->persistent()
                            ->send();

                        return;
                    }

                    $action->success();
                }),
        ];
    }

    /**
     * The admin flag is not mass assignable (see User::$fillable), so the
     * form's toggle is saved on its own.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (array_key_exists('is_admin', $data)) {
            $record->forceFill(['is_admin' => (bool) $data['is_admin']]);
        }

        return parent::handleRecordUpdate($record, Arr::except($data, ['is_admin']));
    }
}

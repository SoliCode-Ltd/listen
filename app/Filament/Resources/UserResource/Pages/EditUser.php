<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
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

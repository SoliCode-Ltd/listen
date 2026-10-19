<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The admin flag is not mass assignable (see User::$fillable), so the
     * form's toggle is saved on its own.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation(Arr::except($data, ['is_admin']));

        if ((bool) ($data['is_admin'] ?? false)) {
            $record->forceFill(['is_admin' => true])->save();
        }

        return $record;
    }
}

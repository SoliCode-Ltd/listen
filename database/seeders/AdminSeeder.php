<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'solicode.co.uk@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('WalledReham2280'),
                'is_admin' => true,
            ]
        );
    }
}

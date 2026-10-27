<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class AdminSeeder extends Seeder
{
    private const EMAIL = 'solicode.co.uk@gmail.com';

    /**
     * Creates the admin account when there is none. An account that already
     * has this email is left exactly as it is, so running the seeder again
     * never resets a password. The password comes from ADMIN_SEED_PASSWORD,
     * or a random one is printed here once; none is written in the code.
     */
    public function run(): void
    {
        if (User::query()->where('email', self::EMAIL)->exists()) {
            $this->command?->info('An account for '.self::EMAIL.' already exists; it was left as it is.');

            return;
        }

        $password = (string) env('ADMIN_SEED_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(24);
        }

        $admin = new User([
            'name' => 'Admin',
            'email' => self::EMAIL,
            'password' => Hash::make($password),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        if ($generated) {
            $this->command?->warn(self::passwordLine($password));
        } else {
            $this->command?->info('Admin account '.self::EMAIL.' created with the password from ADMIN_SEED_PASSWORD.');
        }
    }

    /**
     * The line that shows a new password once. The password is escaped: the console reads
     * "\<" and "\>" as markup and would print a password with them wrong (about one in 160).
     */
    public static function passwordLine(string $password): string
    {
        return 'Admin account '.self::EMAIL.' created. Its password, shown only this once: '.OutputFormatter::escape($password);
    }
}

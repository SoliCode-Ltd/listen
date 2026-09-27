<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class TitanMailbox
{
    public const ADDRESS = 'help@listenai.studio';

    public const HOST = 'smtp.titan.email';

    public const PORT = 465;

    /**
     * Resend is selected in some environments, but the Resend SDK is not installed.
     * Titan is the mailbox that actually sends for listenai.studio.
     */
    public static function preferOverBrokenResend(): void
    {
        if (config('mail.default') !== 'resend' || class_exists(\Resend::class)) {
            return;
        }

        self::apply();
        config(['mail.default' => 'titan']);
    }

    public static function apply(): void
    {
        config([
            'mail.mailers.titan' => [
                'transport' => 'smtp',
                'scheme' => 'smtps',
                'host' => self::host(),
                'port' => self::port(),
                'username' => self::username(),
                'password' => self::password(),
                'timeout' => 20,
                'local_domain' => 'listenai.studio',
            ],
            'mail.from.address' => self::username(),
            'mail.from.name' => self::fromName(),
        ]);

        Mail::purge('titan');
    }

    public static function host(): string
    {
        return self::setting('titan_mail_host', self::HOST);
    }

    public static function port(): int
    {
        $port = (int) self::setting('titan_mail_port', (string) self::PORT);

        return $port > 0 ? $port : self::PORT;
    }

    public static function username(): string
    {
        $username = self::setting('titan_mail_username', self::ADDRESS);

        return filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : self::ADDRESS;
    }

    public static function fromName(): string
    {
        $name = self::setting('titan_mail_from_name', 'Listen');

        return $name !== '' ? $name : 'Listen';
    }

    public static function password(): string
    {
        $stored = self::setting('titan_mail_password', '');
        if ($stored !== '') {
            try {
                return trim(Crypt::decryptString($stored));
            } catch (\Throwable) {
                throw new RuntimeException('The saved Titan password could not be read. Open Newsletter → Mailbox and enter the password for '.self::ADDRESS.' again.');
            }
        }

        $fromEnv = trim((string) env('MAIL_PASSWORD', ''));

        return strtolower($fromEnv) === 'null' ? '' : $fromEnv;
    }

    public static function hasPassword(): bool
    {
        try {
            return self::password() !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function requirePassword(): string
    {
        $password = self::password();
        if ($password === '') {
            throw new RuntimeException('The Titan mailbox password for '.self::ADDRESS.' is missing. Open Newsletter → Mailbox and paste the password you use to sign in to Titan email.');
        }

        return $password;
    }

    public static function storePassword(string $password): void
    {
        SiteSetting::set('titan_mail_password', Crypt::encryptString($password), 'mail');
    }

    private static function setting(string $key, string $default): string
    {
        try {
            $value = trim((string) SiteSetting::get($key, ''));
        } catch (\Throwable) {
            return $default;
        }

        return $value !== '' ? $value : $default;
    }
}

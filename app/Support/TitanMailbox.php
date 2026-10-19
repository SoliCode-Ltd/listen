<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
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

        // This runs on every page: a saved password that can no longer be
        // read (the app key changed) must stop email, not the whole site.
        try {
            self::apply();
        } catch (RuntimeException $e) {
            Log::error($e->getMessage());

            return;
        }
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

    /**
     * Titan's 535 reply includes the AUTH LOGIN challenge "UGFzc3dvcmQ6"
     * (base64 for "Password:"). That token is the server prompt, not the password we sent.
     */
    public static function explainFailure(string $message): string
    {
        $authFailed = str_contains($message, '535')
            || str_contains(strtolower($message), 'authentication failed')
            || str_contains($message, 'UGFzc3dvcmQ6');

        if (! $authFailed) {
            return $message;
        }

        $mailbox = self::username();

        return 'Titan refused SMTP login for '.$mailbox.'. The password did reach the server. '
            .'"UGFzc3dvcmQ6" is Titan\'s own password prompt, not a blank password. '
            .'Sign in at https://app.titan.email/mail/ as '.$mailbox.', open Settings, and turn on Enable Titan on other apps. '
            .'If two-factor authentication is on for that mailbox, Titan blocks every outside mail app until it is turned off. '
            .'Use the password for this mailbox. The Name.com account password is a different password.';
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

<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\TitanMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The mailbox is set up on every page, so a problem with its saved
 * password may stop email but never the site.
 */
class TitanMailboxBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unreadable_saved_password_does_not_stop_the_site(): void
    {
        // Saved under an earlier app key, so it can't be decrypted now.
        SiteSetting::set('titan_mail_password', 'eyJpdiI6IndyaXR0ZW4gd2l0aCBhbiBvbGQga2V5In0=', 'mail');
        config(['mail.default' => 'resend']);
        Log::spy();

        TitanMailbox::preferOverBrokenResend();

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be read'))->once();
        $this->assertFalse(TitanMailbox::hasPassword());
    }

    public function test_a_readable_saved_password_still_switches_to_the_mailbox(): void
    {
        SiteSetting::set('titan_mail_password', Crypt::encryptString('secret'), 'mail');
        config(['mail.default' => 'resend']);

        TitanMailbox::preferOverBrokenResend();

        $this->assertSame('titan', config('mail.default'));
        $this->assertSame('secret', config('mail.mailers.titan.password'));
    }
}

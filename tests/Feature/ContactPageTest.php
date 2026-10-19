<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_page_loads_correctly(): void
    {
        $response = $this->getLocalizedRoute('contact');
        $response->assertStatus(200);
        $response->assertSee('Get in Touch');
        $response->assertSee('Name');
        $response->assertSee('Subject');
        $response->assertSee('Message');
    }

    public function test_a_link_can_pick_the_topic(): void
    {
        $get = fn (string $query) => $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('contact').$query);

        // The pricing page's "Contact us" opens the form about plans and billing.
        $get('?topic=billing')->assertOk()->assertSee('name="subject" value="'.e(__('ui.contact.topic_billing')).'"', false);
        // Anything else leaves the subject empty.
        $get('?topic=%3Cb%3E')->assertOk()->assertSee('name="subject" value=""', false);
    }

    public function test_legacy_contact_url_redirects_to_contact_us(): void
    {
        $contactUrl = $this->localizedRoute('contact');
        $legacyUrl = str_replace('contact-us', 'contact', $contactUrl);

        $response = $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($legacyUrl);

        $response->assertRedirect($contactUrl);
    }

    public function test_contact_form_submission(): void
    {
        Mail::spy();

        SiteSetting::set('contact_submission_email', 'support@example.com', 'contact');
        SiteSetting::set('contact_submission_name', 'Support Team', 'contact');

        $response = $this->postLocalizedRoute('contact.submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Test Subject',
            'message' => 'This is a test message.',
        ]);

        $response->assertSessionHas('success');
        $response->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Test Subject',
        ]);

        Mail::shouldHaveReceived('raw')->once();
    }

    public function test_a_submission_address_saved_empty_falls_back_so_someone_can_reply(): void
    {
        // Mail::fake() ignores Mail::raw(), so this reads the test mailer's outbox (MAIL_MAILER=array): nothing is sent.
        config(['mail.from.address' => 'help@example.com']);
        // As the admin form saves a field left empty.
        SiteSetting::set('contact_submission_email', '', 'contact');
        SiteSetting::set('contact_email', 'team@example.com', 'contact');
        $send = fn () => $this->postLocalizedRoute('contact.submit', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'subject' => 'A plan for my team',
            'message' => 'We make about ten hours of audio a month.',
        ])->assertSessionHas('success');

        $send();
        SiteSetting::set('contact_email', '  ', 'contact');
        $send();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertSame(
            [['team@example.com'], ['help@example.com']],
            $sent->map(fn ($message): array => array_map(fn ($address): string => $address->getAddress(), $message->getOriginalMessage()->getTo()))->all(),
        );
        $this->assertSame('sara@example.com', $sent->first()->getOriginalMessage()->getReplyTo()[0]->getAddress());
    }

    public function test_contact_form_submission_supports_json_requests(): void
    {
        Mail::spy();

        SiteSetting::set('contact_submission_email', 'support@example.com', 'contact');

        $response = $this->postJsonLocalizedRoute('contact.submit', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'JSON Submit',
            'message' => 'This message was sent via AJAX.',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Your message has been sent successfully!',
            ]);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'JSON Submit',
        ]);
    }
}

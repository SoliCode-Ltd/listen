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

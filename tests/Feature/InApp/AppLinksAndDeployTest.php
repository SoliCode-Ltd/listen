<?php

namespace Tests\Feature\InApp;

/**
 * Links to the site open in the Listen app once the site names the app's
 * signing key (/.well-known/assetlinks.json), and the app's own code (mobile/)
 * never goes to the web servers.
 */
class AppLinksAndDeployTest extends InAppTestCase
{
    private const PRINT_A = '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5';

    private const PRINT_B = 'FA:C6:17:45:DC:09:03:78:6F:B9:ED:E6:2A:96:2B:39:9F:73:48:F0:BB:6F:89:9B:83:32:66:75:91:03:3B:9C';

    public function test_no_app_is_named_until_the_signing_key_is_set(): void
    {
        config(['services.android_app.cert_fingerprints' => '']);

        $this->get('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
    }

    public function test_the_site_names_the_app_and_its_signing_keys(): void
    {
        // The upload key and the store's own signing key, as the owner pastes them (any case, spaces).
        config(['services.android_app.cert_fingerprints' => strtolower(self::PRINT_A).' , '.self::PRINT_B.', not-a-fingerprint']);

        $response = $this->get('/.well-known/assetlinks.json')->assertOk();

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'studio.listenai.app',
                'sha256_cert_fingerprints' => [self::PRINT_A, self::PRINT_B],
            ],
        ]]);
        // Google's checker asks without cookies and keeps the answer: no session is started for it.
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_apps_code_is_never_sent_to_the_web_servers(): void
    {
        foreach (['server-deploy.yml', 'live-release-check.yml'] as $workflow) {
            $text = (string) file_get_contents(base_path('.github/workflows/'.$workflow));
            $this->assertSame(1, preg_match("/git ls-files \\| grep -v -E '\\^\\(([^']+)\\)'/", $text, $m), $workflow);
            $this->assertContains('mobile/', explode('|', $m[1]), "$workflow must leave mobile/ out of the server bundle.");
        }
    }

    public function test_the_app_has_its_own_script_and_a_browser_never_loads_it(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->assertFileExists(public_path('js/listen-app.js'));
        $this->inApp($member)->page('app.plan')->assertOk()
            ->assertSee('js/listen-app.js', false)
            ->assertSee('window.LISTEN_APP', false)
            ->assertSee('name="listen-in-app"', false);
        $this->inBrowser($member)->page('dashboard')->assertOk()
            ->assertDontSee('js/listen-app.js', false)
            ->assertDontSee('window.LISTEN_APP', false);
    }

    public function test_account_deletion_can_be_asked_for_from_the_app_and_the_web(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach ([$this->inApp($member), $this->inBrowser(null)] as $as) {
            $as->get($this->localizedRoute('contact').'?topic=delete')->assertOk()
                ->assertSee('value="'.__('ui.contact.topic_delete').'"', false);
        }
        $this->inApp($member)->page('app.plan')->assertSee('topic=delete', false);
    }
}

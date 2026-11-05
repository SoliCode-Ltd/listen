<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A page opened at www. moves to the main address for good (App\Http\Middleware\CanonicalHost):
 * its requests are written with the main address, and from www. the browser refused them.
 */
class CanonicalHostTest extends TestCase
{
    public function test_a_page_at_www_moves_to_the_main_address_with_its_path_and_query(): void
    {
        config(['app.url' => 'https://listen.test']);

        $this->get('https://www.listen.test/ar/pricing?plan=pro')->assertStatus(301)->assertRedirect('https://listen.test/ar/pricing?plan=pro');
        $this->call('HEAD', 'https://www.listen.test/en')->assertStatus(301)->assertRedirect('https://listen.test/en');
    }

    public function test_only_pages_move_and_the_main_address_stays(): void
    {
        config(['app.url' => 'https://listen.test']);

        // A request that is not a page is answered where it was sent.
        $this->assertNotSame(301, $this->post('https://www.listen.test/en/contact', [])->status());
        $this->assertNotSame(301, $this->get('https://listen.test/en')->status());
        // A site whose main address is www. itself keeps it.
        config(['app.url' => 'https://www.listen.test']);
        $this->assertNotSame(301, $this->get('https://www.listen.test/en')->status());
    }
}

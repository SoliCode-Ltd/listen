<?php

namespace Tests\Unit\Agents;

use App\Support\Agents\StepText;
use Tests\TestCase;

/** The install panel's step text: menu names, menu paths and long code, always safe HTML. */
class StepTextTest extends TestCase
{
    public function test_names_become_code_and_a_menu_path_gets_chevrons(): void
    {
        $this->assertSame(
            'Open <code dir="ltr" class="is-path">Plugins</code> <code dir="ltr" class="is-path">Add New Plugin</code> <code dir="ltr">Upload Plugin</code>, then <code dir="ltr">Install Now</code>.',
            StepText::html('Open `Plugins` > `Add New Plugin` > `Upload Plugin`, then `Install Now`.')
        );
    }

    public function test_long_code_gets_its_own_line_and_everything_is_escaped(): void
    {
        $html = StepText::html("Add `'listen' => ['agent_key' => env('LISTEN_AGENT_KEY')],` to `config/services.php` <b>now</b>");
        $this->assertStringContainsString('<code dir="ltr" class="is-long">&#039;listen&#039; =&gt; [&#039;agent_key&#039; =&gt; env(&#039;LISTEN_AGENT_KEY&#039;)],</code>', $html);
        $this->assertStringContainsString('<code dir="ltr">config/services.php</code>', $html);
        $this->assertStringContainsString('&lt;b&gt;now&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_an_arabic_menu_name_reads_right_to_left(): void
    {
        $this->assertSame(
            'افتح <code dir="rtl" class="is-path">إضافات</code> <code dir="rtl">رفع إضافة</code> ثم <code dir="ltr">.zip</code>',
            StepText::html('افتح `إضافات` > `رفع إضافة` ثم `.zip`')
        );
    }

    public function test_a_lone_greater_than_sign_stays_text(): void
    {
        $this->assertSame('5 &gt; 3 and <code dir="ltr">&lt;/body&gt;</code>', StepText::html('5 > 3 and `</body>`'));
    }
}

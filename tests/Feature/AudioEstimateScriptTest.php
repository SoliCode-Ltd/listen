<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UsageEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The studio shows a text's length and cost as it is typed with
 * public/js/audio-estimate.js, and the server reserves it with
 * App\Support\UsageEstimator: the two must agree, or a member would be shown
 * one cost and held to another. Node runs the page's script on the same texts.
 */
class AudioEstimateScriptTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTS = [
        'مالك يا صديقى ايه الى حصل [متوتر] كل مرة تقولى مفيش حاجة بس انا قلقان عليك ما تفهمنى <بلع ريق> بس عامة انت حبيبى <قبلة> ولا تزعل نفسك',
        'السلام عليكم ورحمة الله وبركاته، أهلاً بكم في هذه الحلقة الجديدة. اليوم نتحدث عن الذكاء الاصطناعي!',
        'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ، الْحَمْدُ لِلَّهِ رَبِّ الْعَالَمِينَ.',
        "Welcome back <laugh> to the show.\r\nToday: voices, accents and 2,024 more things… [sic] [1]",
        '[excited] Big news! <short pause> We launched. [sad] But it rained. <long pause> (laughs) The end.',
        '<همس>لا تخبر أحداً</همس> ثم تكلم بصوت عادي. <تقليد صوت سيدة> أهلاً يا حبيبتي </تقليد صوت سيدة>',
        '<whispering>quiet please</whispering> and now loud [laughing] ha ha [/] done',
        'Line one.

Line two, after a blank line.
[whispers] Line three [laughs]',
        '你好，世界。今日は良い天気ですね。안녕하세요',
        'Mixed نص عربي with English words and 3 numbers: 1, 22, 333.',
        "\u{00A0}spaces\u{00A0}around\u{00A0}",
        '[angry]',
        '<laugh>',
        'Only words with no tags at all',
    ];

    public function test_the_page_script_estimates_exactly_as_the_server_does(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed.');
        }

        $studios = [
            [],
            ['performance_style' => 'cinematic', 'emotion' => 'spiritual', 'dialect' => 'ar-XA', 'style_prompt' => 'Slow and grand'],
            ['performance_style' => 'commercial', 'emotion' => 'warm', 'dialect' => 'ar-LB', 'style_prompt' => '  '],
            ['performance_style' => 'ad_style', 'emotion' => 'confident', 'dialect' => 'ar-EG'],
        ];
        $cases = [];
        foreach (self::TEXTS as $text) {
            foreach ([null, 'hd', 'standard'] as $tier) {
                foreach ([1.0, 0.5, 0.8, 1.5, 2.0] as $speed) {
                    foreach ($studios as $studio) {
                        $cases[] = ['text' => $text, 'tier' => $tier, 'speed' => $speed, 'studio' => $studio];
                    }
                }
            }
        }

        $input = tempnam(sys_get_temp_dir(), 'estimate');
        file_put_contents($input, json_encode([
            'script' => public_path('js/audio-estimate.js'),
            'config' => UsageEstimator::clientConfig(),
            'cases' => $cases,
        ], JSON_UNESCAPED_UNICODE));
        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            vm.runInThisContext(fs.readFileSync(input.script, 'utf8'));
            const estimate = globalThis.ListenAudioEstimate;
            estimate.configure(input.config);
            process.stdout.write(JSON.stringify(input.cases.map((c) => {
                const options = Object.assign({ tier: c.tier, speed: c.speed }, Array.isArray(c.studio) ? {} : c.studio);

                return {
                    seconds: estimate.seconds(c.text, options),
                    credits: estimate.credits(c.text, options),
                    features: estimate.features(c.text),
                };
            })));
            JS;
        $process = new Process([$node, '-e', $harness, $input]);
        $process->run();
        @unlink($input);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $results = json_decode($process->getOutput(), true);
        $this->assertCount(count($cases), $results);

        foreach ($cases as $i => $case) {
            $label = json_encode($case, JSON_UNESCAPED_UNICODE);
            $this->assertSame(UsageEstimator::features(trim($case['text'])), $results[$i]['features'], $label);
            $server = UsageEstimator::estimate($case['text'], $case['tier'], $case['speed'], $case['studio']);
            $this->assertSame($server['seconds'], $results[$i]['seconds'], $label);
            $this->assertSame($server['credits'], $results[$i]['credits'], $label);
        }
    }

    public function test_the_studio_loads_the_script_with_the_servers_numbers(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('js/audio-estimate.js', false)
            ->assertSee('window.ListenAudioEstimate?.configure(', false)
            ->assertSee('"credits_per_second":15', false);
    }
}

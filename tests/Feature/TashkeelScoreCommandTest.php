<?php

namespace Tests\Feature;

use App\Console\Commands\TashkeelScoreCommand;
use Tests\TestCase;

/**
 * `tashkeel:score` over a small test set: words said wrong weigh in full,
 * endings a fifth, homographs are listed first, and a kept run that was
 * better in any kind fails the next one.
 */
class TashkeelScoreCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/tashkeel-'.bin2hex(random_bytes(4));
        @mkdir(base_path($this->dir), 0755, true);
        file_put_contents(base_path($this->dir.'/golden.json'), json_encode(['version' => 1, 'items' => [
            ['id' => 'a', 'variety' => 'fusha', 'tags' => ['homograph'], 'approved' => true, 'text' => 'رفع الجندي علم بلاده', 'ref' => 'رَفَعَ الْجُنْدِيُّ عَلَمَ بِلَادِهِ'],
            ['id' => 'b', 'variety' => 'levantine', 'tags' => [], 'approved' => true, 'text' => 'بدي فنجان قهوة', 'ref' => 'بِدِّي فِنْجَان قَهْوِة'],
            ['id' => 'c', 'variety' => 'fusha', 'tags' => [], 'approved' => false, 'text' => 'لم يُراجَع بعد', 'ref' => 'لَمْ يُرَاجَعْ بَعْدُ'],
        ]], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        foreach (glob(base_path($this->dir.'/*')) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(base_path($this->dir));
        @unlink(base_path('tests/tashkeel/results/test-good.json'));
        parent::tearDown();
    }

    public function test_a_word_said_wrong_weighs_five_times_an_ending(): void
    {
        $this->assertSame(10.0, TashkeelScoreCommand::weighted(10, 0));
        $this->assertSame(2.0, TashkeelScoreCommand::weighted(0, 10));
        $this->assertSame(12.0, TashkeelScoreCommand::weighted(10, 10));
    }

    public function test_it_scores_only_approved_sentences_and_fails_when_a_kind_gets_worse(): void
    {
        $candidates = fn (array $marked): string => tap(base_path($this->dir.'/c-'.bin2hex(random_bytes(3)).'.json'), fn (string $f) => file_put_contents($f, json_encode($marked, JSON_UNESCAPED_UNICODE)));

        // A perfect run, kept.
        $this->artisan('tashkeel:score', ['--golden' => $this->dir.'/golden.json', '--save' => 'test-good',
            '--candidates' => $candidates(['a' => 'رَفَعَ الْجُنْدِيُّ عَلَمَ بِلَادِهِ', 'b' => 'بِدِّي فِنْجَان قَهْوِة'])])
            ->expectsOutputToContain('Kept as test-good')
            ->assertSuccessful();
        $kept = json_decode((string) file_get_contents(base_path('tests/tashkeel/results/test-good.json')), true)['summary'];
        $this->assertSame(['all', 'homograph', 'fusha', 'levantine'], array_keys($kept));
        $this->assertSame(2, $kept['all']['sentences'], 'the sentence not approved yet is left out');
        $this->assertSame(0.0, (float) $kept['all']['weighted']);

        // The homograph read as «عِلْم»: worse in "homograph", "fusha" and "all"; Levantine untouched.
        $this->artisan('tashkeel:score', ['--golden' => $this->dir.'/golden.json', '--against' => 'test-good',
            '--candidates' => $candidates(['a' => 'رَفَعَ الْجُنْدِيُّ عِلْمَ بِلَادِهِ', 'b' => 'بِدِّي فِنْجَان قَهْوِة'])])
            ->expectsOutputToContain('Worse than test-good in: all, homograph, fusha')
            ->assertFailed();

        // An ending alone is not "said wrong", so it does not fail the run.
        $this->artisan('tashkeel:score', ['--golden' => $this->dir.'/golden.json', '--against' => 'test-good',
            '--candidates' => $candidates(['a' => 'رَفَعَ الْجُنْدِيَّ عَلَمَ بِلَادِهِ', 'b' => 'بِدِّي فِنْجَان قَهْوِة'])])
            ->assertSuccessful();
    }
}

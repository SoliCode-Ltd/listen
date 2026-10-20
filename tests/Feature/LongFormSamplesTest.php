<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Support\LongFormSamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The voice samples page's long recordings: a card shows only for a take of
 * the text as it is now, with its recording on disk, and every time on it is
 * the measured one.
 */
class LongFormSamplesTest extends TestCase
{
    use RefreshDatabase;

    private string $takes;

    /** @var array<int, string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->takes = tempnam(sys_get_temp_dir(), 'takes');
        LongFormSamples::$takesFile = $this->takes;
        Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Sarah',
            'internal_voice_id' => 'en-Gemini-HD-Sulafat', 'provider' => 'gemini', 'provider_voice_id' => 'Sulafat', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        LongFormSamples::$takesFile = null;
        @unlink($this->takes);
        foreach ($this->made as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_every_text_fits_studio_max_on_live_and_names_no_one_else(): void
    {
        $samples = (require database_path('seeders/data/long_form_samples.php'))['samples'];
        $this->assertNotEmpty($samples);
        foreach ($samples as $sample) {
            $this->assertLessThanOrEqual(8000, mb_strlen($sample['text']), $sample['slug']);
            $this->assertGreaterThan(3000, mb_strlen($sample['text']), $sample['slug']);
            $this->assertContains($sample['language'], ['en', 'ar']);
            $this->assertNotSame(__('ui.samples.long_kind_'.$sample['kind']), 'ui.samples.long_kind_'.$sample['kind']);
            $this->assertDoesNotMatchRegularExpression('/eleven ?labs|gemini|google|openai|\bmurf\b|play\.ht|speechify/i', $sample['title'].' '.$sample['text']);
        }
        foreach (['en', 'ar'] as $locale) {
            foreach (trans('ui.samples', [], $locale) as $key => $line) {
                if (is_string($line) && str_starts_with($key, 'long_')) {
                    $this->assertDoesNotMatchRegularExpression('/eleven ?labs|gemini|google/i', $line, $key);
                }
            }
        }
    }

    public function test_a_measured_take_shows_its_real_times(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);
        $this->take('long-en-clockmaker', ['made_in_seconds' => 41.6, 'audio_seconds' => 511.4]);

        app()->setLocale('en');
        $page = $this->getLocalizedRoute('samples')->assertOk();
        $reads = $page->viewData('longReads');
        $this->assertCount(1, $reads);
        $read = $reads[0];
        $this->assertSame('8:31', $read['listen_time']);
        $this->assertSame('42 s', $read['made_in']);
        // 511.4 / 41.6 = 12.3: never rounded up.
        $this->assertSame(12, $read['times']);
        $this->assertSame('Sarah', $read['speaker']);
        $page->assertSee('id="long-reads"', false);
        $page->assertSee('href="#long-reads"', false);
        $page->assertSee('The Clockmaker of Harrow Lane');
        $page->assertSee('12× faster than listening');
        $page->assertSee('Made on 20 October 2026');
        $page->assertSee('Read by Sarah · 7,800 characters', false);
        $page->assertSeeInOrder(['id="english-accents"', 'id="long-reads"', 'id="moods"'], false);
    }

    public function test_a_card_waits_for_a_take_of_the_text_as_it_is_now(): void
    {
        // Measured for an older version of the text.
        $this->take('long-en-clockmaker', ['text_sha1' => sha1('an older text')]);
        app()->setLocale('en');
        $page = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertSame([], $page->viewData('longReads'));
        $page->assertDontSee('id="long-reads"', false);
    }

    public function test_each_page_shows_only_its_own_language(): void
    {
        $this->take('long-en-clockmaker');
        $this->take('long-ar-calligrapher', ['made_in_seconds' => 52, 'audio_seconds' => 700]);

        app()->setLocale('ar');
        $page = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertSame(['long-ar-calligrapher'], array_column($page->viewData('longReads'), 'id'));
        $page->assertSee('أسرع من الاستماع 13 مرة');
        $page->assertSee('52 ث');
        $page->assertSee('11:40');
        $page->assertDontSee('The Clockmaker of Harrow Lane');
    }

    public function test_times_read_well(): void
    {
        app()->setLocale('en');
        $this->assertSame('0:59', LongFormSamples::clock(59.4));
        $this->assertSame('13:04', LongFormSamples::clock(784));
        $this->assertSame('1:02:05', LongFormSamples::clock(3725));
        $this->assertSame('38 s', LongFormSamples::madeIn(38.2));
        $this->assertSame('1 min 12 s', LongFormSamples::madeIn(72));
    }

    /**
     * A take of $slug's text as it is now, with a recording on disk.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function take(string $slug, array $overrides = []): void
    {
        $sample = collect((require database_path('seeders/data/long_form_samples.php'))['samples'])->firstWhere('slug', $slug);
        $takes = (array) json_decode((string) file_get_contents($this->takes), true);
        $takes[$slug] = $overrides + [
            'made_in_seconds' => 40.0, 'audio_seconds' => 500.0, 'characters' => mb_strlen($sample['text']),
            'pieces' => 9, 'pieces_at_once' => 12, 'recorded_at' => '2026-10-20T03:10:00+00:00', 'site' => 'live',
            'text_sha1' => sha1($sample['text']),
        ];
        file_put_contents($this->takes, json_encode($takes));
        $file = public_path(LongFormSamples::AUDIO_DIR.'/'.$slug.'.mp3');
        if (! is_file($file)) {
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, 'ID3');
            $this->made[] = $file;
        }
    }
}

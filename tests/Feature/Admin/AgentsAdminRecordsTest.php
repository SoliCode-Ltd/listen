<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\AgentResource\Pages\ListAgents;
use App\Filament\Resources\AgentResource\Pages\ViewAgent;
use App\Filament\Resources\AgentResource\RelationManagers\SitesRelationManager;
use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentSource;
use App\Models\AgentWidget;
use App\Models\AgentWidgetSite;
use App\Models\User;
use App\Support\Agents\AgentPurge;
use App\Support\Agents\CallRecordings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's Agents page beyond reading: deleting an agent for good, the websites its call
 * button runs on, and each call's recording (made in the caller's browser, kept on Listen's disk).
 */
class AgentsAdminRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Agent $agent;

    private AgentWidget $widget;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake(CallRecordings::DISK);
        Storage::fake(AgentSource::DISK);
        Cache::flush();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->owner = User::factory()->create(['email' => 'shop@example.test']);
        $this->agent = Agent::create(['user_id' => $this->owner->id, 'name' => 'Huda', 'business_name' => 'Beirut Shoes', 'business_type' => 'retail',
            'language' => 'ar', 'live_voice' => 'Aoede', 'status' => Agent::LIVE]);
        $this->widget = AgentWidget::createFor($this->agent);
        $this->widget->forceFill(['allowed_origins' => ['https://shoes.example']])->save();
    }

    private function makeCall(array $attributes = []): AgentConversation
    {
        return AgentConversation::create(array_merge([
            'agent_id' => $this->agent->id, 'user_id' => $this->owner->id, 'agent_widget_id' => $this->widget->id, 'channel' => 'widget',
            'origin' => 'https://shoes.example', 'status' => AgentConversation::ENDED, 'started_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(4), 'duration_seconds' => 60,
        ], $attributes));
    }

    public function test_the_call_button_notes_every_website_it_loads_on_allowed_or_refused(): void
    {
        $get = fn (string $origin) => $this->withHeaders(['Origin' => $origin])->get('/agent-api/widgets/'.$this->widget->public_key.'?via=wordpress');
        $get('https://shoes.example')->assertOk();
        $get('https://shoes.example')->assertOk();
        $get('https://copycat.example')->assertForbidden();

        $sites = AgentWidgetSite::query()->orderBy('origin')->get();
        $this->assertSame(['https://copycat.example', 'https://shoes.example'], $sites->pluck('origin')->all());
        $this->assertSame([false, true], $sites->pluck('allowed')->all());
        $this->assertSame('wordpress', $sites[1]->installed_via);
        // Noted once every ten minutes a website, not on every page view.
        $this->assertSame(2, AgentWidgetSite::query()->count());

        $this->makeCall();
        $this->makeCall();
        $agent = AgentResource::getEloquentQuery()->find($this->agent->id);
        $this->assertSame(2, (int) $agent->sites_count);
        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->assertTableColumnStateSet('sites_count', 'shoes.example', $this->agent);
        Livewire::actingAs($this->admin)->test(SitesRelationManager::class, ['ownerRecord' => $this->agent, 'pageClass' => ViewAgent::class])
            ->assertCanSeeTableRecords($sites)
            ->assertSee('shoes.example')->assertSee('copycat.example')
            ->assertSee('Shows there')->assertSee('Refused (not on its list)')
            ->assertTableColumnStateSet('calls', 2, $sites[1]);
    }

    public function test_listen_s_own_address_is_not_a_website_of_a_member_s_agent(): void
    {
        $this->withHeaders(['Origin' => rtrim(url('/'), '/')])->get('/agent-api/widgets/'.$this->widget->public_key);
        $this->assertSame(0, AgentWidgetSite::query()->count());
    }

    public function test_a_call_s_recording_comes_in_parts_in_order_and_the_admin_plays_it(): void
    {
        $call = $this->makeCall(['status' => AgentConversation::LIVE, 'ended_at' => null, 'call_key_hash' => hash('sha256', 'key-1'), 'public_id' => 'cnv_'.str_repeat('a', 24)]);
        $send = fn (int $part, string $bytes, string $type = 'audio/webm;codecs=opus', string $key = 'key-1') => $this->call('POST', '/agent-api/calls/'.$call->public_id.'/recording', [], [], [],
            ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://shoes.example'],
            json_encode(['call_key' => $key, 'part' => $part, 'type' => $type, 'audio' => base64_encode($bytes)]));
        $head = "\x1A\x45\xDF\xA3".'first-part';

        $send(0, $head, 'audio/webm;codecs=opus', 'wrong')->assertNotFound();
        // A first part that is not a recording's start is refused.
        $send(0, 'not-audio')->assertStatus(422)->assertJsonPath('result', 'bad');
        $send(1, 'too-early')->assertStatus(422)->assertJsonPath('result', 'order');
        $send(0, $head)->assertOk()->assertJsonPath('result', 'kept');
        $send(0, $head)->assertOk()->assertJsonPath('result', 'again');
        $send(1, '-second')->assertOk()->assertJsonPath('result', 'kept');
        $send(2, 'x', 'audio/mp4')->assertStatus(422)->assertJsonPath('result', 'bad');

        $call->refresh();
        $this->assertSame('agent-recordings/'.$call->started_at->format('Y/m').'/'.$call->public_id.'.webm', $call->recording_path);
        $this->assertSame(2, (int) $call->recording_parts);
        $this->assertSame($head.'-second', Storage::disk(CallRecordings::DISK)->get($call->recording_path));

        // Too late after the call ended: no more parts.
        $call->forceFill(['ended_at' => now()->subMinutes(10)])->save();
        $send(2, 'late')->assertStatus(422)->assertJsonPath('result', 'late');

        $this->actingAs($this->admin)->get(route('admin.agent-calls.recording', ['call' => $call->public_id]))
            ->assertOk()->assertHeader('Content-Type', 'audio/webm');
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.agent-calls.recording', ['call' => $call->public_id]))->assertForbidden();
        Livewire::actingAs($this->admin)->test(ViewAgentConversation::class, ['record' => $call->getRouteKey()])
            ->assertSee('Recording')->assertSee(route('admin.agent-calls.recording', ['call' => $call->public_id]), false);
    }

    public function test_a_whole_recording_is_packed_again_so_it_has_its_length(): void
    {
        // Two parts of a real browser recording of a pretend call (silent agent, test microphone tone).
        $real = base_path('tests/fixtures/agent-call-recording.webm');
        if (! is_file($real) || \App\Support\AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('Needs ffmpeg and a browser recording.');
        }
        Storage::disk(CallRecordings::DISK)->put('agent-recordings/r.webm', file_get_contents($real));
        $call = $this->makeCall(['recording_path' => 'agent-recordings/r.webm', 'recording_type' => 'audio/webm', 'recording_bytes' => filesize($real), 'recording_parts' => 2]);

        $this->assertTrue(CallRecordings::finish($call));
        $probe = new \Symfony\Component\Process\Process([str_replace('ffmpeg', 'ffprobe', \App\Support\AudioFormats::ffmpegPath()), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', Storage::disk(CallRecordings::DISK)->path('agent-recordings/r.webm')]);
        $probe->run();
        $this->assertGreaterThan(5, (float) trim($probe->getOutput()));
    }

    public function test_recordings_older_than_the_keep_days_go_and_the_words_stay(): void
    {
        $old = $this->makeCall(['started_at' => now()->subDays(CallRecordings::KEEP_DAYS + 1), 'recording_path' => 'agent-recordings/old.webm', 'recording_type' => 'audio/webm', 'recording_bytes' => 4,
            'transcript' => [['role' => 'agent', 'text' => 'Hi']]]);
        $new = $this->makeCall(['recording_path' => 'agent-recordings/new.webm', 'recording_type' => 'audio/webm', 'recording_bytes' => 4]);
        Storage::disk(CallRecordings::DISK)->put('agent-recordings/old.webm', 'old!');
        Storage::disk(CallRecordings::DISK)->put('agent-recordings/new.webm', 'new!');

        $this->assertSame(1, CallRecordings::prune());
        Storage::disk(CallRecordings::DISK)->assertMissing('agent-recordings/old.webm');
        Storage::disk(CallRecordings::DISK)->assertExists('agent-recordings/new.webm');
        $this->assertNull($old->fresh()->recording_path);
        $this->assertSame('Hi', $old->fresh()->transcript[0]['text']);
    }

    public function test_the_start_of_a_call_says_whether_it_is_recorded(): void
    {
        $this->assertTrue(CallRecordings::enabled());
        \App\Models\SiteSetting::set(CallRecordings::SETTING, '0', 'agents');
        $this->assertFalse(CallRecordings::enabled());
        $call = $this->makeCall(['call_key_hash' => hash('sha256', 'k'), 'public_id' => 'cnv_'.str_repeat('b', 24), 'status' => AgentConversation::LIVE, 'ended_at' => null]);
        $this->call('POST', '/agent-api/calls/'.$call->public_id.'/recording', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
            json_encode(['call_key' => 'k', 'part' => 0, 'type' => 'audio/webm', 'audio' => base64_encode("\x1A\x45\xDF\xA3")]))
            ->assertOk()->assertJsonPath('result', 'off');
    }

    public function test_an_agent_is_deleted_for_good_with_its_calls_recordings_files_and_buttons(): void
    {
        $call = $this->makeCall(['recording_path' => 'agent-recordings/x.webm', 'recording_type' => 'audio/webm', 'recording_bytes' => 3]);
        Storage::disk(CallRecordings::DISK)->put('agent-recordings/x.webm', 'abc');
        Storage::disk(AgentSource::DISK)->put('agents/x/menu.txt', 'menu');
        AgentSource::create(['agent_id' => $this->agent->id, 'kind' => AgentSource::FILE, 'title' => 'menu.txt', 'path' => 'agents/x/menu.txt', 'status' => AgentSource::READY, 'content' => 'menu']);
        AgentWidgetSite::note($this->widget, 'https://shoes.example', true);
        $contact = AgentContact::create(['user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'agent_conversation_id' => $call->id, 'name' => 'Rami', 'phone' => '+96170000000']);
        $live = $this->makeCall(['status' => AgentConversation::LIVE, 'ended_at' => null, 'last_seen_at' => now()]);

        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->callTableAction('delete', $this->agent)
            ->assertNotified('Agent deleted');

        $this->assertNull(Agent::withTrashed()->find($this->agent->id));
        $this->assertSame(0, AgentConversation::query()->where('agent_id', $this->agent->id)->count());
        $this->assertNull(AgentWidget::query()->find($this->widget->id));
        $this->assertSame(0, AgentWidgetSite::query()->count());
        $this->assertNull(AgentConversation::query()->find($live->id));
        Storage::disk(CallRecordings::DISK)->assertMissing('agent-recordings/x.webm');
        Storage::disk(AgentSource::DISK)->assertMissing('agents/x/menu.txt');
        // The customers it saved stay in the owner's list.
        $this->assertNotNull($contact->fresh());
        $this->assertNull($contact->fresh()->agent_id);
    }

    public function test_listen_s_own_agent_is_never_deleted_here(): void
    {
        $house = Agent::create(['user_id' => $this->admin->id, 'name' => 'Sara', 'business_name' => 'Listen', 'business_type' => 'services',
            'language' => 'en', 'status' => Agent::LIVE, 'settings' => ['house' => 'en']]);
        $this->assertFalse(AgentPurge::purge($house));
        $this->assertNotNull($house->fresh());
        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->callTableBulkAction('delete', [$house, $this->agent]);
        $this->assertNotNull($house->fresh());
        $this->assertNull(Agent::withTrashed()->find($this->agent->id));
    }

    public function test_calls_are_under_each_agent_not_in_the_menu(): void
    {
        $this->assertFalse(AgentConversationResource::shouldRegisterNavigation());
        $this->actingAs($this->admin)->get(AgentResource::getUrl('index'))->assertOk()
            ->assertDontSee('href="'.AgentConversationResource::getUrl('index').'"', false);
        $this->actingAs($this->admin)->get(AgentResource::getUrl('view', ['record' => $this->agent]))->assertOk()
            ->assertSee('Websites')->assertSee('Delete for good');
    }
}

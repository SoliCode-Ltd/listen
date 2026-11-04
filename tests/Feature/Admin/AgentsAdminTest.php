<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AgentContactResource;
use App\Filament\Resources\AgentContactResource\Pages\ListAgentContacts;
use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\AgentResource\Pages\ListAgents;
use App\Filament\Resources\AgentResource\Pages\ViewAgent;
use App\Filament\Resources\AgentResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\AgentResource\RelationManagers\ConversationsRelationManager;
use App\Filament\Widgets\AgentStats;
use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentSource;
use App\Models\AgentWidget;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Agents\HouseAgent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agents in the admin (Agents → Agents, Calls, Customers): an admin reads
 * every agent with its setup, sources, call button and figures, every call
 * with its whole conversation and what it took, and every customer an agent
 * saved. Nothing there can be changed, and members cannot open it.
 */
class AgentsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Agent $agent;

    private Agent $house;

    private AgentWidget $widget;

    private AgentConversation $call;

    private AgentConversation $houseCall;

    private AgentContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->owner = User::factory()->create(['email' => 'clinic-owner@example.test', 'name' => 'Mona Adel']);

        $this->agent = Agent::create([
            'user_id' => $this->owner->id,
            'name' => 'سارة',
            'business_name' => 'Cairo Smiles Clinic',
            'business_type' => 'healthcare',
            'jobs' => ['answer', 'leads', 'booking'],
            'website_url' => 'https://cairosmiles.example',
            'language' => 'ar',
            'dialect' => 'ar-EG',
            'live_voice' => 'Callirrhoe',
            'first_message' => 'أهلاً بحضرتك في عيادة كايرو سمايلز.',
            'script' => [
                'identity' => 'سارة المساعدة الصوتية لعيادة كايرو سمايلز.',
                'never' => ['Never give a medical diagnosis.', 'لا تحجز أي موعد يوم الجمعة.'],
                'steps' => ['Greet the caller warmly.', 'Take their name and phone number.'],
                'handoff' => 'When the caller has a dental emergency.',
            ],
            'knowledge' => "Teeth whitening: 1,500 EGP.\nOpen every day from 10 am to 10 pm except Friday.",
            'choices' => [[
                'question' => 'Should it book appointments itself?',
                'options' => ['Book them', 'Take the details for the team'],
                'answer' => 'Take the details for the team',
            ]],
            'settings' => ['greeting' => 'warm'],
            'status' => Agent::LIVE,
            'went_live_at' => now()->subDays(3),
        ]);
        $this->widget = AgentWidget::createFor($this->agent);
        $this->widget->forceFill([
            'allowed_origins' => ['https://cairosmiles.example'],
            'installed_via' => 'wordpress',
            'last_seen_origin' => 'https://cairosmiles.example',
            'last_seen_at' => now()->subHour(),
        ])->save();
        AgentSource::create([
            'agent_id' => $this->agent->id, 'kind' => AgentSource::WEBSITE, 'url' => 'https://cairosmiles.example',
            'title' => 'Cairo Smiles home page', 'content' => 'Whitening 1,500 EGP', 'chars' => 19,
            'status' => 'failed', 'error' => 'The website did not answer in time.', 'read_at' => now(),
        ]);
        AgentSource::create([
            'agent_id' => $this->agent->id, 'kind' => AgentSource::TEXT, 'title' => 'Notes',
            'content' => 'Closed on Friday.', 'chars' => 17, 'status' => 'ready', 'read_at' => now(),
        ]);

        $this->call = AgentConversation::create([
            'agent_id' => $this->agent->id,
            'user_id' => $this->owner->id,
            'agent_widget_id' => $this->widget->id,
            'channel' => 'widget',
            'visitor_hash' => hash('sha256', 'visitor-1'),
            'origin' => 'https://cairosmiles.example',
            'page_url' => 'https://cairosmiles.example/prices',
            'country' => 'EG',
            'status' => AgentConversation::ENDED,
            'end_reason' => 'handoff',
            'started_at' => now()->subMinutes(30),
            'last_seen_at' => now()->subMinutes(29),
            'ended_at' => now()->subMinutes(28),
            'duration_seconds' => 95,
            'billed_seconds' => 95,
            'credits' => 1425,
            'reservation' => ['period' => now()->format('Y-m'), 'billed_to_pack' => false, 'credits' => 9000, 'seconds' => 600],
            'period_ym' => now()->format('Y-m'),
            'free' => false,
            'settled_at' => now()->subMinutes(28),
            'transcript' => [
                ['role' => 'agent', 'text' => 'أهلاً بحضرتك، معاك سارة. أقدر أساعدك إزاي؟', 'at' => 0],
                ['role' => 'caller', 'text' => 'عايز أحجز تنظيف أسنان', 'at' => 4],
                ['role' => 'agent', 'text' => 'تمام، ممكن اسم حضرتك ورقم الموبايل؟', 'at' => 7],
                ['role' => 'caller', 'text' => 'Omar, 01001234567', 'at' => 12],
            ],
            'summary' => 'Omar wants a teeth cleaning; the team will call him back.',
            'lead' => ['name' => 'Omar', 'phone' => '+201001234567', 'note' => 'Teeth cleaning'],
            'outcome' => 'callback',
            'usage' => [
                'promptTokenCount' => 5200, 'responseTokenCount' => 2100, 'thoughtsTokenCount' => 40,
                'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 4000], ['modality' => 'TEXT', 'tokenCount' => 1200]],
                'responseTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 2100]],
            ],
            'cost_usd' => 0.03,
        ]);
        $this->contact = AgentContact::create([
            'user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'agent_conversation_id' => $this->call->id,
            'name' => 'Omar', 'phone' => '+201001234567', 'note' => 'Teeth cleaning', 'last_contact_at' => now()->subMinutes(28),
        ]);

        // Listen's own agent (HouseAgent): its calls are on Listen.
        $this->house = Agent::create([
            'user_id' => $this->admin->id, 'name' => 'Sara', 'business_name' => 'Listen', 'business_type' => 'services',
            'language' => 'en', 'live_voice' => 'Achernar', 'status' => Agent::LIVE, 'settings' => ['house' => 'en'],
        ]);
        $houseWidget = AgentWidget::createFor($this->house);
        SiteSetting::set(HouseAgent::SETTING, json_encode(['en' => ['agent' => $this->house->public_id, 'key' => $houseWidget->public_key, 'slug' => $houseWidget->slug]]), 'agents');
        $this->houseCall = AgentConversation::create([
            'agent_id' => $this->house->id, 'user_id' => $this->admin->id, 'agent_widget_id' => $houseWidget->id,
            'channel' => 'link', 'status' => AgentConversation::ENDED, 'end_reason' => 'done',
            'started_at' => now()->subHours(2), 'last_seen_at' => now()->subHours(2), 'ended_at' => now()->subHours(2),
            'duration_seconds' => 60, 'billed_seconds' => 60, 'credits' => 0, 'free' => true,
            'reservation' => ['seconds' => 180, 'credits' => 0, 'free' => true], 'settled_at' => now()->subHours(2),
            'transcript' => [['role' => 'agent', 'text' => 'Hi, this is Sara from Listen.', 'at' => 0]],
        ]);
    }

    public function test_an_admin_sees_every_agent_with_its_owner_language_voice_and_figures(): void
    {
        $this->actingAs($this->admin)->get(AgentResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Agents at a glance')
            ->assertSee('clinic-owner@example.test')
            ->assertSee(AgentResource::ownerUrl($this->owner->id), false)
            ->assertSee('Cairo Smiles Clinic')
            ->assertSee('Egyptian (Cairene)')
            ->assertSee('Callirrhoe')
            ->assertSee('Listen\'s own')
            ->assertSee('1.6 min')
            ->assertSee('1,425');
    }

    public function test_the_agents_list_filters_listen_s_own_agents_agents_without_calls_and_searches_owners(): void
    {
        $quiet = Agent::create([
            'user_id' => $this->owner->id, 'name' => 'Huda', 'business_name' => 'Quiet Shop', 'business_type' => 'retail',
            'language' => 'ar', 'dialect' => 'ar-SA', 'status' => Agent::DRAFT,
        ]);

        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->assertCanSeeTableRecords([$this->agent, $this->house, $quiet])
            ->filterTable('house', true)
            ->assertCanSeeTableRecords([$this->house])
            ->assertCanNotSeeTableRecords([$this->agent, $quiet])
            ->filterTable('house', false)
            ->assertCanSeeTableRecords([$this->agent, $quiet])
            ->assertCanNotSeeTableRecords([$this->house])
            ->resetTableFilters()
            ->filterTable('has_calls', false)
            ->assertCanSeeTableRecords([$quiet])
            ->assertCanNotSeeTableRecords([$this->agent, $this->house])
            ->resetTableFilters()
            ->searchTable('clinic-owner@')
            ->assertCanSeeTableRecords([$this->agent, $quiet])
            ->assertCanNotSeeTableRecords([$this->house]);
    }

    public function test_an_agent_page_shows_its_setup_sources_call_button_and_totals(): void
    {
        $this->actingAs($this->admin)->get(AgentResource::getUrl('view', ['record' => $this->agent]))
            ->assertOk()
            ->assertSee('Cairo Smiles Clinic')
            ->assertSee(__('agents.types.healthcare'))
            ->assertSee(__('agents.jobs.booking'))
            ->assertSee('clinic-owner@example.test')
            ->assertSee('Egyptian (Cairene)')
            ->assertSee('Callirrhoe')
            ->assertSee('أهلاً بحضرتك في عيادة كايرو سمايلز.')
            ->assertSee('سارة المساعدة الصوتية لعيادة كايرو سمايلز.')
            ->assertSee('Never give a medical diagnosis.')
            ->assertSee('لا تحجز أي موعد يوم الجمعة.')
            ->assertSeeInOrder(['Greet the caller warmly.', 'Take their name and phone number.'])
            ->assertSee('When the caller has a dental emergency.')
            ->assertSee('Should it book appointments itself?')
            ->assertSee('Take the details for the team')
            ->assertSee('Teeth whitening: 1,500 EGP.')
            ->assertSee('Cairo Smiles home page')
            ->assertSee('The website did not answer in time.')
            ->assertSee('Typed by the owner')
            ->assertSee($this->widget->public_key)
            ->assertSee('/talk/'.$this->widget->slug, false)
            ->assertSee('WordPress plugin')
            ->assertSee('1,425')
            ->assertSee('1.6 min');
    }

    public function test_an_agent_s_calls_and_customers_are_listed_under_it(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ConversationsRelationManager::class, ['ownerRecord' => $this->agent, 'pageClass' => ViewAgent::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->call])
            ->assertCanNotSeeTableRecords([$this->houseCall]);

        Livewire::actingAs($this->admin)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $this->agent, 'pageClass' => ViewAgent::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->contact]);
    }

    public function test_the_calls_list_shows_every_call_with_where_it_came_from_and_what_it_took(): void
    {
        $this->actingAs($this->admin)->get(AgentConversationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Website button')
            ->assertSee('cairosmiles.example')
            ->assertSee('Passed to a person')
            ->assertSee('1:35')
            ->assertSee('1,425')
            ->assertSee('Wants a call back')
            ->assertSee('<bdi>Omar</bdi>', false)
            ->assertSee('+201001234567')
            ->assertSee('Link page');

        Livewire::actingAs($this->admin)->test(ListAgentConversations::class)
            ->assertCanSeeTableRecords([$this->call, $this->houseCall])
            ->filterTable('channel', 'link')
            ->assertCanSeeTableRecords([$this->houseCall])
            ->assertCanNotSeeTableRecords([$this->call])
            ->resetTableFilters()
            ->filterTable('free', false)
            ->assertCanSeeTableRecords([$this->call])
            ->assertCanNotSeeTableRecords([$this->houseCall])
            ->resetTableFilters()
            ->filterTable('agent_id', $this->house->id)
            ->assertCanSeeTableRecords([$this->houseCall])
            ->assertCanNotSeeTableRecords([$this->call])
            ->resetTableFilters()
            ->filterTable('started_at', ['from' => now()->subHour()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$this->call])
            // The tab on its own: Listen's own call was two hours ago, which is yesterday just after midnight.
            ->resetTableFilters()
            ->set('activeTab', 'house')
            ->assertCanSeeTableRecords([$this->houseCall])
            ->assertCanNotSeeTableRecords([$this->call]);
    }

    public function test_a_call_page_shows_the_whole_conversation_in_order_and_what_it_took(): void
    {
        $this->actingAs($this->admin)->get(AgentConversationResource::getUrl('view', ['record' => $this->call]))
            ->assertOk()
            ->assertSeeInOrder([
                'أهلاً بحضرتك، معاك سارة. أقدر أساعدك إزاي؟',
                'عايز أحجز تنظيف أسنان',
                'تمام، ممكن اسم حضرتك ورقم الموبايل؟',
                'Omar, 01001234567',
            ])
            ->assertSee('class="lsa-bubble-text" dir="auto"', false)
            ->assertSee('Omar wants a teeth cleaning; the team will call him back.')
            ->assertSee('+201001234567')
            ->assertSee('Teeth cleaning')
            ->assertSee('Passed to a person')
            ->assertSee(AgentConversationResource::END_REASON_HELP['handoff'])
            ->assertSeeInOrder(['Held when it started', '9,000 credits', 'for up to 10:00 (600 s)'])
            ->assertSee('1,425 credits')
            ->assertSee('7,575 credits (505 s unused)')
            ->assertSeeInOrder(['Tokens in', '5,200', '4,000 sound · 1,200 text'])
            ->assertSeeInOrder(['Tokens out', '2,100', '2,100 sound'])
            ->assertSee('$0.03000')
            ->assertSee('Egypt')
            ->assertSee('https://cairosmiles.example/prices')
            ->assertDontSee($this->call->call_key_hash ?? 'no-call-key', false);
    }

    public function test_a_free_call_of_listen_s_own_agent_says_so(): void
    {
        $this->actingAs($this->admin)->get(AgentConversationResource::getUrl('view', ['record' => $this->houseCall]))
            ->assertOk()
            ->assertSee('Hi, this is Sara from Listen.')
            ->assertSee('Listen (its own agent)')
            ->assertSeeInOrder(['No credits', 'a free call of up to 3:00 (180 s)']);
    }

    public function test_the_customers_list_shows_who_left_their_details_and_opens_their_call(): void
    {
        $this->actingAs($this->admin)->get(AgentContactResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Omar')
            ->assertSee('+201001234567')
            ->assertSee('Teeth cleaning')
            ->assertSee('clinic-owner@example.test')
            ->assertSee('Cairo Smiles Clinic')
            ->assertSee(AgentConversationResource::getUrl('view', ['record' => $this->call]), false);

        Livewire::actingAs($this->admin)->test(ListAgentContacts::class)
            ->searchTable('clinic-owner@')
            ->assertCanSeeTableRecords([$this->contact])
            ->searchTable('nobody@')
            ->assertCanNotSeeTableRecords([$this->contact]);
    }

    public function test_an_agent_the_owner_deleted_stays_readable_with_its_calls(): void
    {
        $this->agent->delete();

        $this->actingAs($this->admin)->get(AgentResource::getUrl('view', ['record' => $this->agent]))
            ->assertOk()
            ->assertSee('Deleted by the owner');
        $this->actingAs($this->admin)->get(AgentConversationResource::getUrl('view', ['record' => $this->call]))
            ->assertOk()
            ->assertSee('deleted by the owner');

        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->assertCanSeeTableRecords([$this->agent])
            ->filterTable('deleted', false)
            ->assertCanNotSeeTableRecords([$this->agent]);
    }

    public function test_the_stats_count_calls_minutes_credits_and_listen_s_own_agents(): void
    {
        Livewire::actingAs($this->admin)->test(AgentStats::class)
            ->assertSeeInOrder(['Agents', '2', '2 live'])
            ->assertSeeInOrder(['Calls today', '2'])
            ->assertSeeInOrder(['Calls in 7 days', '2', '1 left customer details'])
            ->assertSeeInOrder(['Calls in 30 days', '2', '1 on websites · 1 on link pages'])
            ->assertSeeInOrder(['Minutes in 30 days', '2.6 min'])
            ->assertSeeInOrder(['Credits in 30 days', '1,425'])
            ->assertSeeInOrder(['Listen&#039;s own agents, 30 days', '1 min', '1 call, on Listen'], false);
    }

    public function test_the_calls_menu_counts_the_calls_live_right_now(): void
    {
        $this->assertNull(AgentConversationResource::getNavigationBadge());

        $live = ['agent_id' => $this->agent->id, 'user_id' => $this->owner->id, 'channel' => 'widget', 'status' => AgentConversation::LIVE, 'started_at' => now()->subMinute()];
        AgentConversation::create($live + ['last_seen_at' => now()->subSeconds(5)]);
        // A call whose browser went quiet is settled later as "left"; it is not live any more.
        AgentConversation::create($live + ['last_seen_at' => now()->subMinutes(5)]);

        $this->assertSame('1', AgentConversationResource::getNavigationBadge());
    }

    public function test_nothing_in_agents_can_be_added_changed_or_deleted(): void
    {
        foreach ([AgentResource::class, AgentConversationResource::class, AgentContactResource::class] as $resource) {
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
            $this->assertSame([], array_intersect(['create', 'edit'], array_keys($resource::getPages())), $resource);
        }
        foreach ([$this->agent, $this->call, $this->contact] as $record) {
            $resource = Filament::getModelResource($record);
            $this->assertFalse($resource::canEdit($record));
            $this->assertFalse($resource::canDelete($record));
        }

        Livewire::actingAs($this->admin)->test(ListAgents::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
        Livewire::actingAs($this->admin)->test(ListAgentConversations::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_members_cannot_open_any_of_it(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_admin_sign_in(): void
    {
        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        }
    }

    /** @return array<int, string> */
    private function adminUrls(): array
    {
        return [
            AgentResource::getUrl('index'),
            AgentResource::getUrl('view', ['record' => $this->agent]),
            AgentConversationResource::getUrl('index'),
            AgentConversationResource::getUrl('view', ['record' => $this->call]),
            AgentContactResource::getUrl('index'),
        ];
    }
}

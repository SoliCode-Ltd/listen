<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Voice;
use App\Support\GeminiTtsModel;
use App\Support\StandardVoiceEngine;
use App\Support\TtsStudioConfig;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TtsSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'TTS Settings';

    protected static string $view = 'filament.pages.tts-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $plans = Plan::where('is_active', true)->pluck('name', 'slug')->toArray();
        $planOverrides = [];
        foreach ($plans as $slug => $name) {
            $planOverrides[$slug] = SiteSetting::get("tts_plan_provider_{$slug}", '');
        }

        $this->form->fill([
            // Global mode
            'tts_mode' => SiteSetting::get('tts_mode', config('listen.tts.mode', 'google')),
            'tts_fallback_enabled' => (string) SiteSetting::get('tts_fallback_enabled', '1') === '1',
            StandardVoiceEngine::SETTING_KEY => StandardVoiceEngine::current(),

            // Gemini config
            'gemini_api_key_set' => count(app(\App\Services\Tts\GeminiKeyManager::class)->getAllKeys()) > 0,
            'gemini_tts_model' => GeminiTtsModel::resolve(),
            'gemini_default_voice' => SiteSetting::get('gemini_default_voice', config('services.gemini_tts.default_voice', 'Kore')),
            'gemini_timeout_seconds' => (int) SiteSetting::get('gemini_timeout_seconds', config('listen.tts.gemini_timeout_seconds', 30)),

            // Per-plan overrides
            'plan_overrides' => $planOverrides,

            // Studio defaults used by Dashboard + Demo
            'tts_default_voice_ar' => $this->resolveStoredOrFallbackDefaultVoice('ar'),
            'tts_default_voice_en' => $this->resolveStoredOrFallbackDefaultVoice('en'),
            'tts_default_performance_style_ar' => TtsStudioConfig::defaultPerformanceStyle('ar'),
            'tts_default_performance_style_en' => TtsStudioConfig::defaultPerformanceStyle('en'),
            'tts_default_emotion_ar' => TtsStudioConfig::defaultEmotion('ar'),
            'tts_default_emotion_en' => TtsStudioConfig::defaultEmotion('en'),
            'tts_default_dialect_ar' => TtsStudioConfig::defaultDialect('ar'),
            'tts_default_dialect_en' => TtsStudioConfig::defaultDialect('en'),

            // Admin-extensible catalogs
            'tts_performance_styles_catalog' => $this->decodeJsonCatalogSetting('tts_performance_styles_catalog'),
            'tts_emotions_catalog' => $this->decodeJsonCatalogSetting('tts_emotions_catalog'),
            'tts_dialects_catalog_ar' => $this->decodeJsonCatalogSetting('tts_dialects_catalog_ar'),
            'tts_dialects_catalog_en' => $this->decodeJsonCatalogSetting('tts_dialects_catalog_en'),
        ]);
    }

    public function form(Form $form): Form
    {
        $plans = Plan::where('is_active', true)->pluck('name', 'slug')->toArray();
        $performanceStyleOptions = collect(TtsStudioConfig::performanceStyleCatalog())
            ->mapWithKeys(fn (array $row): array => [$row['value'] => $row['label']])
            ->all();
        $emotionOptions = collect(TtsStudioConfig::emotionCatalog())
            ->mapWithKeys(fn (array $row): array => [$row['value'] => $row['label']])
            ->all();
        $arabicDialectOptions = collect(TtsStudioConfig::dialectCatalogForLanguage('ar'))
            ->mapWithKeys(fn (array $row): array => [$row['value'] => $row['label']])
            ->all();
        $englishDialectOptions = collect(TtsStudioConfig::dialectCatalogForLanguage('en'))
            ->mapWithKeys(fn (array $row): array => [$row['value'] => $row['label']])
            ->all();
        $arabicVoiceOptions = $this->voiceOptionsForLanguage('ar');
        $englishVoiceOptions = $this->voiceOptionsForLanguage('en');

        return $form
            ->schema([
                Forms\Components\Section::make('Global TTS Mode')
                    ->description('Controls which TTS engine is used for audio generation across the platform.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('tts_mode')
                            ->label('TTS Routing Mode')
                            ->options([
                                'google' => 'Google Cloud TTS (WaveNet & Chirp)',
                                'hybrid' => 'Hybrid (Standard → Google, HD → Gemini)',
                            ])
                            ->required()
                            ->helperText('Google: all users get Google Cloud TTS (WaveNet + Chirp). Hybrid: standard voices use Google (the engine chosen here), HD voices on paid plans use the Gemini model selected below, with automatic fallback.')
                            ->reactive(),
                        Forms\Components\Select::make(StandardVoiceEngine::SETTING_KEY)
                            ->label('Standard voice engine')
                            ->options(StandardVoiceEngine::options())
                            ->default(StandardVoiceEngine::WAVENET)
                            ->required()
                            ->helperText('Reads every Standard-quality generation, including the free plan and the demo. With Chirp 3 HD, each standard voice in the picker is read by a matching Chirp 3 HD voice of the same language and gender. Pitch is ignored by Chirp 3 HD. Switch back at any time.'),
                        Forms\Components\Toggle::make('tts_fallback_enabled')
                            ->label('Enable Fallback')
                            ->helperText('If Gemini fails, automatically fall back to Google Cloud TTS.')
                            ->default(true)
                            ->visible(fn (Forms\Get $get): bool => $get('tts_mode') === 'hybrid'),
                    ]),

                Forms\Components\Section::make('Gemini TTS Configuration')
                    ->description('Choose the Gemini speech model. The next HD generation uses the model you save here.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('gemini_tts_model')
                            ->label('Gemini model')
                            ->options(GeminiTtsModel::options())
                            ->required()
                            ->helperText('Current keeps Gemini 2.5. Newest is Gemini 3.8 Flash. Newest Lite is Gemini 3.8 Flash-Lite. Standard voices stay on Google Cloud. HD voices, and any plan forced to Gemini, use this choice.'),
                        Forms\Components\Placeholder::make('gemini_api_key_status')
                            ->label('Gemini API Key')
                            ->content(fn (Forms\Get $get): string => $get('gemini_api_key_set')
                                ? '✅ Configured (set via GEMINI_TTS_API_KEY env variable)'
                                : '❌ Not configured — set GEMINI_TTS_API_KEY in your .env file'),
                        Forms\Components\Select::make('gemini_default_voice')
                            ->label('Default Gemini Voice')
                            ->options([
                                'Zephyr' => 'Zephyr (Bright)',
                                'Puck' => 'Puck (Upbeat)',
                                'Charon' => 'Charon (Informative)',
                                'Kore' => 'Kore (Firm)',
                                'Fenrir' => 'Fenrir (Excitable)',
                                'Leda' => 'Leda (Youthful)',
                                'Orus' => 'Orus (Firm)',
                                'Aoede' => 'Aoede (Breezy)',
                                'Callirrhoe' => 'Callirrhoe (Easy-going)',
                                'Autonoe' => 'Autonoe (Bright)',
                            ])
                            ->required()
                            ->helperText('Used when no specific voice is requested by the user.'),
                        Forms\Components\TextInput::make('gemini_timeout_seconds')
                            ->label('Gemini Timeout (seconds)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(120)
                            ->default(30)
                            ->helperText('Maximum time to wait for Gemini API response before falling back.'),
                    ]),

                Forms\Components\Section::make('Per-Plan Provider Overrides')
                    ->description('Override the TTS provider for specific plans. Leave empty to use the global mode.')
                    ->visible(fn (Forms\Get $get): bool => $get('tts_mode') === 'hybrid')
                    ->schema(
                        collect($plans)->map(fn (string $name, string $slug) => Forms\Components\Select::make("plan_overrides.{$slug}")
                            ->label("{$name} Plan")
                            ->options([
                                '' => 'Use Global Mode',
                                'google_cloud' => 'Force Google Cloud TTS',
                                'gemini' => 'Force Gemini TTS',
                            ])
                            ->default('')
                            ->helperText("Override provider for the {$name} plan.")
                        )->values()->toArray()
                    ),

                Forms\Components\Tabs::make('studio_focus_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Studio Defaults')
                            ->schema([
                                Forms\Components\Section::make('Studio Defaults (Demo + Dashboard)')
                                    ->description('These defaults are used when the user has not chosen a custom value yet.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('tts_default_voice_ar')
                                            ->label('Default Arabic Speaker')
                                            ->options($arabicVoiceOptions)
                                            ->searchable()
                                            ->placeholder('Use automatic fallback'),
                                        Forms\Components\Select::make('tts_default_voice_en')
                                            ->label('Default English Speaker')
                                            ->options($englishVoiceOptions)
                                            ->searchable()
                                            ->placeholder('Use automatic fallback'),
                                        Forms\Components\Select::make('tts_default_performance_style_ar')
                                            ->label('Default Arabic Performance Style')
                                            ->options($performanceStyleOptions)
                                            ->searchable()
                                            ->required(),
                                        Forms\Components\Select::make('tts_default_performance_style_en')
                                            ->label('Default English Performance Style')
                                            ->options($performanceStyleOptions)
                                            ->searchable()
                                            ->required(),
                                        Forms\Components\Select::make('tts_default_emotion_ar')
                                            ->label('Default Arabic Emotion')
                                            ->options($emotionOptions)
                                            ->searchable()
                                            ->required(),
                                        Forms\Components\Select::make('tts_default_emotion_en')
                                            ->label('Default English Emotion')
                                            ->options($emotionOptions)
                                            ->searchable()
                                            ->required(),
                                        Forms\Components\Select::make('tts_default_dialect_ar')
                                            ->label('Default Arabic Dialect')
                                            ->options(['' => 'Auto-detect'] + $arabicDialectOptions)
                                            ->searchable(),
                                        Forms\Components\Select::make('tts_default_dialect_en')
                                            ->label('Default English Dialect')
                                            ->options(['' => 'Auto-detect'] + $englishDialectOptions)
                                            ->searchable(),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Custom Studio Catalogs')
                            ->schema([
                                Forms\Components\Section::make('Custom Studio Catalogs')
                                    ->description('Add custom options and optional Gemini instruction prompts.')
                                    ->schema([
                                        Forms\Components\Repeater::make('tts_performance_styles_catalog')
                                            ->label('Custom Performance Styles')
                                            ->schema($this->styleCatalogRowSchema())
                                            ->default([])
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                                            ->addActionLabel('Add performance style'),
                                        Forms\Components\Repeater::make('tts_emotions_catalog')
                                            ->label('Custom Emotions')
                                            ->schema($this->emotionCatalogRowSchema())
                                            ->default([])
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                                            ->addActionLabel('Add emotion'),
                                        Forms\Components\Repeater::make('tts_dialects_catalog_ar')
                                            ->label('Custom Arabic Dialects')
                                            ->schema($this->catalogRowSchema())
                                            ->default([])
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                                            ->addActionLabel('Add Arabic dialect'),
                                        Forms\Components\Repeater::make('tts_dialects_catalog_en')
                                            ->label('Custom English Dialects')
                                            ->schema($this->catalogRowSchema())
                                            ->default([])
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                                            ->addActionLabel('Add English dialect'),
                                    ]),
                            ]),
                    ]),

                Forms\Components\Section::make('API Keys Status')
                    ->description('Manage API keys in the dedicated Gemini API Keys page.')
                    ->schema([
                        Forms\Components\Placeholder::make('keys_info')
                            ->content(function () {
                                $stats = app(\App\Services\Tts\GeminiKeyManager::class)->getAggregateStats();

                                return "🔑 {$stats['total_keys']} API keys configured, {$stats['available_keys']} available now";
                            }),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('manage_keys')
                                ->label('Manage Gemini API Keys')
                                ->icon('heroicon-o-key')
                                ->url(fn () => route('filament.admin.pages.gemini-keys'))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $group = 'tts';

        // Save scalar settings
        $scalarKeys = [
            'tts_mode',
            'tts_fallback_enabled',
            StandardVoiceEngine::SETTING_KEY,
            'gemini_tts_model',
            'gemini_default_voice',
            'gemini_timeout_seconds',
            'tts_default_voice_ar',
            'tts_default_voice_en',
            'tts_default_performance_style_ar',
            'tts_default_performance_style_en',
            'tts_default_emotion_ar',
            'tts_default_emotion_en',
            'tts_default_dialect_ar',
            'tts_default_dialect_en',
        ];

        foreach ($scalarKeys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, $group);
            }
        }

        // Save per-plan overrides
        if (isset($data['plan_overrides']) && is_array($data['plan_overrides'])) {
            foreach ($data['plan_overrides'] as $slug => $provider) {
                SiteSetting::set("tts_plan_provider_{$slug}", (string) $provider, $group);
            }
        }

        $jsonCatalogKeys = [
            'tts_performance_styles_catalog',
            'tts_emotions_catalog',
            'tts_dialects_catalog_ar',
            'tts_dialects_catalog_en',
        ];

        foreach ($jsonCatalogKeys as $key) {
            $rows = $data[$key] ?? [];
            SiteSetting::set($key, json_encode(is_array($rows) ? array_values($rows) : [], JSON_UNESCAPED_UNICODE), $group);
        }

        SiteSetting::flushGroupCache($group);

        Notification::make()
            ->title('TTS settings saved')
            ->success()
            ->send();
    }

    /**
     * Get real-time usage statistics for the dashboard.
     */
    public function getUsageStats(): array
    {
        return app(\App\Services\Tts\GeminiKeyManager::class)->getAggregateStats();
    }

    /**
     * Reset today's usage counters (admin action).
     */
    public function resetCounters(): void
    {
        app(\App\Services\Tts\GeminiKeyManager::class)->resetAllCounters();

        Notification::make()
            ->title('Usage counters reset')
            ->body('All Gemini API key counters have been reset.')
            ->success()
            ->send();
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function catalogRowSchema(): array
    {
        return [
            Forms\Components\TextInput::make('value')
                ->label('Value')
                ->required()
                ->helperText('Slug/code (letters, numbers, underscore, dash). Examples: confident_plus, ar-EG')
                ->regex('/^[A-Za-z0-9_-]+$/')
                ->maxLength(50),
            Forms\Components\TextInput::make('label')
                ->label('Label (EN)')
                ->required()
                ->maxLength(120),
            Forms\Components\TextInput::make('label_ar')
                ->label('Label (AR)')
                ->maxLength(120),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function styleCatalogRowSchema(): array
    {
        $base = $this->catalogRowSchema();
        $base[] = Forms\Components\Textarea::make('instruction_en')
            ->label('Instruction Prompt (EN)')
            ->rows(3)
            ->maxLength(2000)
            ->helperText('Optional detailed instruction used by Gemini for this style when custom prompt is empty.');
        $base[] = Forms\Components\Textarea::make('instruction_ar')
            ->label('Instruction Prompt (AR)')
            ->rows(3)
            ->maxLength(2000)
            ->helperText('Arabic variant of instruction prompt (optional).');

        return $base;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function emotionCatalogRowSchema(): array
    {
        $base = $this->catalogRowSchema();
        $base[] = Forms\Components\Textarea::make('instruction_en')
            ->label('Instruction Prompt (EN)')
            ->rows(3)
            ->maxLength(2000)
            ->helperText('Optional detailed instruction used by Gemini for this emotion when custom prompt is empty.');
        $base[] = Forms\Components\Textarea::make('instruction_ar')
            ->label('Instruction Prompt (AR)')
            ->rows(3)
            ->maxLength(2000)
            ->helperText('Arabic variant of instruction prompt (optional).');

        return $base;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeJsonCatalogSetting(string $key): array
    {
        $raw = SiteSetting::get($key, '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function resolveStoredOrFallbackDefaultVoice(string $language): string
    {
        $language = strtolower(trim($language)) === 'en' ? 'en' : 'ar';
        $stored = trim((string) SiteSetting::get("tts_default_voice_{$language}", ''));
        if ($stored !== '') {
            return $stored;
        }

        $preferredProvider = VoiceCatalog::preferredProviderForTier(VoiceTier::HD);

        return VoiceCatalog::defaultForLanguageAndProvider($language, VoiceTier::HD, $preferredProvider);
    }

    /**
     * @return array<string, string>
     */
    private function voiceOptionsForLanguage(string $language): array
    {
        $language = strtolower(trim($language)) === 'en' ? 'en' : 'ar';

        return Voice::query()
            ->where('is_active', true)
            ->where('language', $language)
            ->orderBy('provider')
            ->orderBy('tier')
            ->orderBy('display_name')
            ->get(['internal_voice_id', 'display_name', 'provider', 'tier'])
            ->mapWithKeys(function (Voice $voice): array {
                $provider = trim((string) $voice->provider);
                $tier = VoiceTier::label((string) $voice->tier);
                $label = trim((string) $voice->display_name) !== ''
                    ? (string) $voice->display_name
                    : (string) $voice->internal_voice_id;

                return [
                    (string) $voice->internal_voice_id => "{$label} ({$provider}, {$tier})",
                ];
            })
            ->all();
    }
}

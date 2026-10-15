<?php

namespace Database\Seeders;

use App\Models\Voice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VoiceSeeder extends Seeder
{
    public function run(): void
    {
        $voiceGroups = [
            [
                'language' => 'ar',
                'tier' => 'standard',
                'voices' => [
                    ['id' => 'ar-XA-Standard-A', 'gender' => 'female'],
                    ['id' => 'ar-XA-Standard-B', 'gender' => 'male'],
                    ['id' => 'ar-XA-Standard-C', 'gender' => 'male'],
                    ['id' => 'ar-XA-Standard-D', 'gender' => 'female'],
                ],
            ],
            [
                'language' => 'ar',
                'tier' => 'standard',
                'voices' => [
                    ['id' => 'ar-XA-Wavenet-A', 'gender' => 'female'],
                    ['id' => 'ar-XA-Wavenet-B', 'gender' => 'male'],
                    ['id' => 'ar-XA-Wavenet-C', 'gender' => 'male'],
                    ['id' => 'ar-XA-Wavenet-D', 'gender' => 'female'],
                ],
            ],
            [
                'language' => 'ar',
                'tier' => 'hd',
                'voices' => [
                    ['id' => 'ar-XA-Chirp3-HD-Achernar', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Achird', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Algenib', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Algieba', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Alnilam', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Aoede', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Autonoe', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Callirrhoe', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Charon', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Despina', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Enceladus', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Erinome', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Fenrir', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Gacrux', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Iapetus', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Kore', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Laomedeia', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Leda', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Orus', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Puck', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Pulcherrima', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Rasalgethi', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Sadachbia', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Sadaltager', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Schedar', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Sulafat', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Umbriel', 'gender' => 'male'],
                    ['id' => 'ar-XA-Chirp3-HD-Vindemiatrix', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Zephyr', 'gender' => 'female'],
                    ['id' => 'ar-XA-Chirp3-HD-Zubenelgenubi', 'gender' => 'male'],
                ],
            ],
            [
                'language' => 'en',
                'tier' => 'standard',
                'voices' => [
                    ['id' => 'en-US-Standard-A', 'gender' => 'male'],
                    ['id' => 'en-US-Standard-B', 'gender' => 'male'],
                    ['id' => 'en-US-Standard-C', 'gender' => 'female'],
                    ['id' => 'en-US-Standard-D', 'gender' => 'male'],
                    ['id' => 'en-US-Standard-E', 'gender' => 'female'],
                    ['id' => 'en-US-Standard-F', 'gender' => 'female'],
                    ['id' => 'en-US-Standard-G', 'gender' => 'female'],
                    ['id' => 'en-US-Standard-H', 'gender' => 'female'],
                    ['id' => 'en-US-Standard-I', 'gender' => 'male'],
                    ['id' => 'en-US-Standard-J', 'gender' => 'male'],
                ],
            ],
            [
                'language' => 'en',
                'tier' => 'standard',
                'voices' => [
                    ['id' => 'en-US-Wavenet-A', 'gender' => 'male'],
                    ['id' => 'en-US-Wavenet-B', 'gender' => 'male'],
                    ['id' => 'en-US-Wavenet-C', 'gender' => 'female'],
                    ['id' => 'en-US-Wavenet-D', 'gender' => 'male'],
                    ['id' => 'en-US-Wavenet-E', 'gender' => 'female'],
                    ['id' => 'en-US-Wavenet-F', 'gender' => 'female'],
                    ['id' => 'en-US-Wavenet-G', 'gender' => 'female'],
                    ['id' => 'en-US-Wavenet-H', 'gender' => 'female'],
                    ['id' => 'en-US-Wavenet-I', 'gender' => 'male'],
                    ['id' => 'en-US-Wavenet-J', 'gender' => 'male'],
                ],
            ],
            [
                'language' => 'en',
                'tier' => 'hd',
                'voices' => [
                    ['id' => 'en-US-Chirp3-HD-Achernar', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Achird', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Algenib', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Algieba', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Alnilam', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Aoede', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Autonoe', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Callirrhoe', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Charon', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Despina', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Enceladus', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Erinome', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Fenrir', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Gacrux', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Iapetus', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Kore', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Laomedeia', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Leda', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Orus', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Puck', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Pulcherrima', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Rasalgethi', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Sadachbia', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Sadaltager', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Schedar', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Sulafat', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Umbriel', 'gender' => 'male'],
                    ['id' => 'en-US-Chirp3-HD-Vindemiatrix', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Zephyr', 'gender' => 'female'],
                    ['id' => 'en-US-Chirp3-HD-Zubenelgenubi', 'gender' => 'male'],
                ],
            ],

            // ─── Gemini HD voices (Arabic) ─── provider: gemini
            [
                'language' => 'ar',
                'tier' => 'hd',
                'provider' => 'gemini',
                'voices' => [
                    ['id' => 'ar-Gemini-HD-Achernar', 'gender' => 'female', 'display_name' => 'Fatima', 'provider_voice_id' => 'Achernar'],
                    ['id' => 'ar-Gemini-HD-Achird', 'gender' => 'male', 'display_name' => 'Nawaf', 'provider_voice_id' => 'Achird'],
                    ['id' => 'ar-Gemini-HD-Algenib', 'gender' => 'male', 'display_name' => 'Sultan', 'provider_voice_id' => 'Algenib'],
                    ['id' => 'ar-Gemini-HD-Algieba', 'gender' => 'male', 'display_name' => 'Turki', 'provider_voice_id' => 'Algieba'],
                    ['id' => 'ar-Gemini-HD-Alnilam', 'gender' => 'male', 'display_name' => 'Faisal', 'provider_voice_id' => 'Alnilam'],
                    ['id' => 'ar-Gemini-HD-Aoede', 'gender' => 'female', 'display_name' => 'Khadija', 'provider_voice_id' => 'Aoede'],
                    ['id' => 'ar-Gemini-HD-Autonoe', 'gender' => 'female', 'display_name' => 'Ruqaya', 'provider_voice_id' => 'Autonoe'],
                    ['id' => 'ar-Gemini-HD-Callirrhoe', 'gender' => 'female', 'display_name' => 'Sumaya', 'provider_voice_id' => 'Callirrhoe'],
                    ['id' => 'ar-Gemini-HD-Charon', 'gender' => 'male', 'display_name' => 'Waleed', 'provider_voice_id' => 'Charon'],
                    ['id' => 'ar-Gemini-HD-Despina', 'gender' => 'female', 'display_name' => 'Maha', 'provider_voice_id' => 'Despina'],
                    ['id' => 'ar-Gemini-HD-Enceladus', 'gender' => 'male', 'display_name' => 'Mansour', 'provider_voice_id' => 'Enceladus'],
                    ['id' => 'ar-Gemini-HD-Erinome', 'gender' => 'female', 'display_name' => 'Aisha', 'provider_voice_id' => 'Erinome'],
                    ['id' => 'ar-Gemini-HD-Fenrir', 'gender' => 'male', 'display_name' => 'Saud', 'provider_voice_id' => 'Fenrir'],
                    ['id' => 'ar-Gemini-HD-Gacrux', 'gender' => 'female', 'display_name' => 'Duaa', 'provider_voice_id' => 'Gacrux'],
                    ['id' => 'ar-Gemini-HD-Iapetus', 'gender' => 'male', 'display_name' => 'Fahad', 'provider_voice_id' => 'Iapetus'],
                    ['id' => 'ar-Gemini-HD-Kore', 'gender' => 'female', 'display_name' => 'Lamya', 'provider_voice_id' => 'Kore'],
                    ['id' => 'ar-Gemini-HD-Laomedeia', 'gender' => 'female', 'display_name' => 'Lubna', 'provider_voice_id' => 'Laomedeia'],
                    ['id' => 'ar-Gemini-HD-Leda', 'gender' => 'female', 'display_name' => 'Noura', 'provider_voice_id' => 'Leda'],
                    ['id' => 'ar-Gemini-HD-Orus', 'gender' => 'male', 'display_name' => 'Abdulaziz', 'provider_voice_id' => 'Orus'],
                    ['id' => 'ar-Gemini-HD-Puck', 'gender' => 'male', 'display_name' => 'Othman', 'provider_voice_id' => 'Puck'],
                    ['id' => 'ar-Gemini-HD-Pulcherrima', 'gender' => 'female', 'display_name' => 'Abeer', 'provider_voice_id' => 'Pulcherrima'],
                    ['id' => 'ar-Gemini-HD-Rasalgethi', 'gender' => 'male', 'display_name' => 'Mishary', 'provider_voice_id' => 'Rasalgethi'],
                    ['id' => 'ar-Gemini-HD-Sadachbia', 'gender' => 'male', 'display_name' => 'Hamed', 'provider_voice_id' => 'Sadachbia'],
                    ['id' => 'ar-Gemini-HD-Sadaltager', 'gender' => 'male', 'display_name' => 'Tamim', 'provider_voice_id' => 'Sadaltager'],
                    ['id' => 'ar-Gemini-HD-Schedar', 'gender' => 'male', 'display_name' => 'Hazem', 'provider_voice_id' => 'Schedar'],
                    ['id' => 'ar-Gemini-HD-Sulafat', 'gender' => 'female', 'display_name' => 'Manal', 'provider_voice_id' => 'Sulafat'],
                    ['id' => 'ar-Gemini-HD-Umbriel', 'gender' => 'male', 'display_name' => 'Raed', 'provider_voice_id' => 'Umbriel'],
                    ['id' => 'ar-Gemini-HD-Vindemiatrix', 'gender' => 'female', 'display_name' => 'Jumana', 'provider_voice_id' => 'Vindemiatrix'],
                    ['id' => 'ar-Gemini-HD-Zephyr', 'gender' => 'female', 'display_name' => 'Sawsan', 'provider_voice_id' => 'Zephyr'],
                    ['id' => 'ar-Gemini-HD-Zubenelgenubi', 'gender' => 'male', 'display_name' => 'Alaa', 'provider_voice_id' => 'Zubenelgenubi'],
                ],
            ],

            // ─── Gemini HD voices (English) ─── provider: gemini
            [
                'language' => 'en',
                'tier' => 'hd',
                'provider' => 'gemini',
                'voices' => [
                    ['id' => 'en-Gemini-HD-Achernar', 'gender' => 'female', 'display_name' => 'Alice', 'provider_voice_id' => 'Achernar'],
                    ['id' => 'en-Gemini-HD-Achird', 'gender' => 'male', 'display_name' => 'Oliver', 'provider_voice_id' => 'Achird'],
                    ['id' => 'en-Gemini-HD-Algenib', 'gender' => 'male', 'display_name' => 'George', 'provider_voice_id' => 'Algenib'],
                    ['id' => 'en-Gemini-HD-Algieba', 'gender' => 'male', 'display_name' => 'Oscar', 'provider_voice_id' => 'Algieba'],
                    ['id' => 'en-Gemini-HD-Alnilam', 'gender' => 'male', 'display_name' => 'Charlie', 'provider_voice_id' => 'Alnilam'],
                    ['id' => 'en-Gemini-HD-Aoede', 'gender' => 'female', 'display_name' => 'Ruby', 'provider_voice_id' => 'Aoede'],
                    ['id' => 'en-Gemini-HD-Autonoe', 'gender' => 'female', 'display_name' => 'Ivy', 'provider_voice_id' => 'Autonoe'],
                    ['id' => 'en-Gemini-HD-Callirrhoe', 'gender' => 'female', 'display_name' => 'Maya', 'provider_voice_id' => 'Callirrhoe'],
                    ['id' => 'en-Gemini-HD-Charon', 'gender' => 'male', 'display_name' => 'Theodore', 'provider_voice_id' => 'Charon'],
                    ['id' => 'en-Gemini-HD-Despina', 'gender' => 'female', 'display_name' => 'Luna', 'provider_voice_id' => 'Despina'],
                    ['id' => 'en-Gemini-HD-Enceladus', 'gender' => 'male', 'display_name' => 'Arthur', 'provider_voice_id' => 'Enceladus'],
                    ['id' => 'en-Gemini-HD-Erinome', 'gender' => 'female', 'display_name' => 'Willow', 'provider_voice_id' => 'Erinome'],
                    ['id' => 'en-Gemini-HD-Fenrir', 'gender' => 'male', 'display_name' => 'Finn', 'provider_voice_id' => 'Fenrir'],
                    ['id' => 'en-Gemini-HD-Gacrux', 'gender' => 'female', 'display_name' => 'Isla', 'provider_voice_id' => 'Gacrux'],
                    ['id' => 'en-Gemini-HD-Iapetus', 'gender' => 'male', 'display_name' => 'Miles', 'provider_voice_id' => 'Iapetus'],
                    ['id' => 'en-Gemini-HD-Kore', 'gender' => 'female', 'display_name' => 'Daisy', 'provider_voice_id' => 'Kore'],
                    ['id' => 'en-Gemini-HD-Laomedeia', 'gender' => 'female', 'display_name' => 'Poppy', 'provider_voice_id' => 'Laomedeia'],
                    ['id' => 'en-Gemini-HD-Leda', 'gender' => 'female', 'display_name' => 'Sienna', 'provider_voice_id' => 'Leda'],
                    ['id' => 'en-Gemini-HD-Orus', 'gender' => 'male', 'display_name' => 'Nathan', 'provider_voice_id' => 'Orus'],
                    ['id' => 'en-Gemini-HD-Puck', 'gender' => 'male', 'display_name' => 'Dylan', 'provider_voice_id' => 'Puck'],
                    ['id' => 'en-Gemini-HD-Pulcherrima', 'gender' => 'female', 'display_name' => 'Freya', 'provider_voice_id' => 'Pulcherrima'],
                    ['id' => 'en-Gemini-HD-Rasalgethi', 'gender' => 'male', 'display_name' => 'Ryan', 'provider_voice_id' => 'Rasalgethi'],
                    ['id' => 'en-Gemini-HD-Sadachbia', 'gender' => 'male', 'display_name' => 'Adrian', 'provider_voice_id' => 'Sadachbia'],
                    ['id' => 'en-Gemini-HD-Sadaltager', 'gender' => 'male', 'display_name' => 'Caleb', 'provider_voice_id' => 'Sadaltager'],
                    ['id' => 'en-Gemini-HD-Schedar', 'gender' => 'male', 'display_name' => 'Marcus', 'provider_voice_id' => 'Schedar'],
                    ['id' => 'en-Gemini-HD-Sulafat', 'gender' => 'female', 'display_name' => 'Phoebe', 'provider_voice_id' => 'Sulafat'],
                    ['id' => 'en-Gemini-HD-Umbriel', 'gender' => 'male', 'display_name' => 'Vincent', 'provider_voice_id' => 'Umbriel'],
                    ['id' => 'en-Gemini-HD-Vindemiatrix', 'gender' => 'female', 'display_name' => 'Rose', 'provider_voice_id' => 'Vindemiatrix'],
                    ['id' => 'en-Gemini-HD-Zephyr', 'gender' => 'female', 'display_name' => 'Clara', 'provider_voice_id' => 'Zephyr'],
                    ['id' => 'en-Gemini-HD-Zubenelgenubi', 'gender' => 'male', 'display_name' => 'Philip', 'provider_voice_id' => 'Zubenelgenubi'],
                ],
            ],
        ];

        $namePools = [
            'ar' => [
                'female' => [
                    'Sara', 'Mariam', 'Nour', 'Layla', 'Farah', 'Huda', 'Amina', 'Salma', 'Yara', 'Dina',
                    'Rania', 'Hana', 'Reem', 'Amal', 'Nada', 'Samira', 'Lina', 'Zainab', 'Aya', 'Dalia',
                    'Iman', 'Nawal', 'Basma', 'Muna', 'Najwa', 'Sahar', 'Wafaa', 'Rasha', 'Heba', 'Nadine',
                ],
                'male' => [
                    'Omar', 'Ahmed', 'Youssef', 'Khaled', 'Karim', 'Hamza', 'Ali', 'Hassan', 'Tarek', 'Samir',
                    'Fadi', 'Ziad', 'Majed', 'Nabil', 'Adel', 'Bilal', 'Rami', 'Saad', 'Anas', 'Marwan',
                    'Hisham', 'Imad', 'Jamal', 'Kamel', 'Mazen', 'Bader', 'Wael', 'Nasser', 'Murad', 'Riyad',
                ],
            ],
            'en' => [
                'female' => [
                    'Emma', 'Olivia', 'Sophia', 'Ava', 'Mia', 'Amelia', 'Charlotte', 'Harper', 'Evelyn', 'Abigail',
                    'Ella', 'Lily', 'Scarlett', 'Grace', 'Chloe', 'Victoria', 'Zoe', 'Hannah', 'Aria', 'Nora',
                    'Lucy', 'Stella', 'Hazel', 'Penelope', 'Addison', 'Audrey', 'Bella', 'Claire', 'Naomi', 'Elena',
                ],
                'male' => [
                    'Liam', 'Noah', 'James', 'William', 'Benjamin', 'Lucas', 'Henry', 'Alexander', 'Mason', 'Michael',
                    'Ethan', 'Daniel', 'Jacob', 'Logan', 'Jackson', 'Sebastian', 'Jack', 'Aiden', 'Owen', 'Samuel',
                    'Matthew', 'Joseph', 'Carter', 'David', 'John', 'Leo', 'Gabriel', 'Julian', 'Isaac', 'Thomas',
                ],
            ],
        ];

        $voices = $this->buildVoiceRows($voiceGroups, $namePools);

        $this->assertNoConflicts($voices);

        DB::transaction(function () use ($voices) {
            Voice::query()->delete();

            foreach ($voices as $voice) {
                Voice::query()->create($voice + ['is_active' => true]);
            }
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $voiceGroups
     * @param  array<string, array<string, array<int, string>>>  $namePools
     * @return array<int, array<string, string>>
     */
    private function buildVoiceRows(array $voiceGroups, array $namePools): array
    {
        $indexes = [];
        $rows = [];

        foreach ($voiceGroups as $group) {
            $language = (string) ($group['language'] ?? '');
            $tier = (string) ($group['tier'] ?? '');
            $groupProvider = (string) ($group['provider'] ?? 'google_cloud');
            $voices = is_array($group['voices'] ?? null) ? $group['voices'] : [];

            foreach ($voices as $voice) {
                $gender = strtolower(trim((string) ($voice['gender'] ?? '')));
                $voiceId = trim((string) ($voice['id'] ?? ''));

                // If the voice definition includes an explicit display_name, use it directly
                // (used for Gemini voices which use celestial names).
                $explicitDisplayName = trim((string) ($voice['display_name'] ?? ''));
                $explicitProviderVoiceId = trim((string) ($voice['provider_voice_id'] ?? ''));

                if ($explicitDisplayName !== '') {
                    $displayName = $explicitDisplayName;
                } else {
                    if (! isset($namePools[$language][$gender])) {
                        throw new \RuntimeException("Name pool not configured for language={$language}, gender={$gender}");
                    }

                    $indexes[$language][$gender] = (int) ($indexes[$language][$gender] ?? 0);
                    $nameIndex = $indexes[$language][$gender];
                    $displayName = $namePools[$language][$gender][$nameIndex] ?? null;

                    if (! is_string($displayName) || $displayName === '') {
                        throw new \RuntimeException("Not enough display names for language={$language}, gender={$gender}");
                    }

                    $indexes[$language][$gender]++;
                }

                $rows[] = [
                    'language' => $language,
                    'gender' => $gender,
                    'tier' => $tier,
                    'display_name' => $displayName,
                    'internal_voice_id' => $voiceId,
                    'provider' => $groupProvider,
                    'provider_voice_id' => $explicitProviderVoiceId !== '' ? $explicitProviderVoiceId : $voiceId,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $voices
     */
    private function assertNoConflicts(array $voices): void
    {
        $seenVoiceIds = [];
        $seenDisplayNames = [];

        foreach ($voices as $voice) {
            $id = strtolower(trim((string) ($voice['internal_voice_id'] ?? '')));
            $language = strtolower(trim((string) ($voice['language'] ?? '')));
            $tier = strtolower(trim((string) ($voice['tier'] ?? '')));
            $provider = strtolower(trim((string) ($voice['provider'] ?? 'google_cloud')));
            $displayName = strtolower(trim((string) ($voice['display_name'] ?? '')));

            if ($id === '' || $displayName === '' || $language === '' || $tier === '') {
                throw new \RuntimeException('VoiceSeeder definitions contain empty required fields.');
            }

            if (isset($seenVoiceIds[$id])) {
                throw new \RuntimeException("Duplicate internal voice id detected in VoiceSeeder: {$id}");
            }
            $seenVoiceIds[$id] = true;

            // Display name uniqueness is per language + provider to allow Gemini and
            // Google Cloud voices with the same celestial display name.
            $displayKey = $language.'|'.$provider.'|'.$displayName;
            if (isset($seenDisplayNames[$displayKey])) {
                throw new \RuntimeException("Duplicate display name detected for language '{$language}', provider '{$provider}': {$displayName}");
            }
            $seenDisplayNames[$displayKey] = true;

            if (! str_starts_with($id, $language.'-')) {
                throw new \RuntimeException("Voice language mismatch in VoiceSeeder: {$id} for language {$language}");
            }

            $engineTokens = match (true) {
                $tier === 'standard' => ['-standard-', '-wavenet-'],
                $tier === 'hd' && $provider === 'gemini' => ['-gemini-hd-'],
                $tier === 'hd' => ['-chirp3-hd-'],
                default => throw new \RuntimeException("Unknown tier in VoiceSeeder: {$tier}"),
            };

            $matchesEngine = false;
            foreach ($engineTokens as $token) {
                if (str_contains($id, $token)) {
                    $matchesEngine = true;
                    break;
                }
            }
            if (! $matchesEngine) {
                throw new \RuntimeException("Tier/engine mismatch in VoiceSeeder for {$id} at tier {$tier}");
            }
        }
    }
}

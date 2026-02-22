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
                'tier' => 'hd',
                'voices' => [
                    ['id' => 'ar-XA-Wavenet-A', 'gender' => 'female'],
                    ['id' => 'ar-XA-Wavenet-B', 'gender' => 'male'],
                    ['id' => 'ar-XA-Wavenet-C', 'gender' => 'male'],
                    ['id' => 'ar-XA-Wavenet-D', 'gender' => 'female'],
                ],
            ],
            [
                'language' => 'ar',
                'tier' => 'hd_plus',
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
                'tier' => 'hd',
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
                'tier' => 'hd_plus',
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
     * @param array<int, array<string, mixed>> $voiceGroups
     * @param array<string, array<string, array<int, string>>> $namePools
     * @return array<int, array<string, string>>
     */
    private function buildVoiceRows(array $voiceGroups, array $namePools): array
    {
        $indexes = [];
        $rows = [];

        foreach ($voiceGroups as $group) {
            $language = (string) ($group['language'] ?? '');
            $tier = (string) ($group['tier'] ?? '');
            $voices = is_array($group['voices'] ?? null) ? $group['voices'] : [];

            foreach ($voices as $voice) {
                $gender = strtolower(trim((string) ($voice['gender'] ?? '')));
                $voiceId = trim((string) ($voice['id'] ?? ''));

                if (! isset($namePools[$language][$gender])) {
                    throw new \RuntimeException("Name pool not configured for language={$language}, gender={$gender}");
                }

                $indexes[$language][$gender] = (int) ($indexes[$language][$gender] ?? 0);
                $nameIndex = $indexes[$language][$gender];
                $displayName = $namePools[$language][$gender][$nameIndex] ?? null;

                if (! is_string($displayName) || $displayName === '') {
                    throw new \RuntimeException("Not enough display names for language={$language}, gender={$gender}");
                }

                $rows[] = [
                    'language' => $language,
                    'gender' => $gender,
                    'tier' => $tier,
                    'display_name' => $displayName,
                    'internal_voice_id' => $voiceId,
                ];

                $indexes[$language][$gender]++;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $voices
     */
    private function assertNoConflicts(array $voices): void
    {
        $seenVoiceIds = [];
        $seenDisplayNames = [];

        foreach ($voices as $voice) {
            $id = strtolower(trim((string) ($voice['internal_voice_id'] ?? '')));
            $language = strtolower(trim((string) ($voice['language'] ?? '')));
            $tier = strtolower(trim((string) ($voice['tier'] ?? '')));
            $displayName = strtolower(trim((string) ($voice['display_name'] ?? '')));

            if ($id === '' || $displayName === '' || $language === '' || $tier === '') {
                throw new \RuntimeException('VoiceSeeder definitions contain empty required fields.');
            }

            if (isset($seenVoiceIds[$id])) {
                throw new \RuntimeException("Duplicate internal voice id detected in VoiceSeeder: {$id}");
            }
            $seenVoiceIds[$id] = true;

            $displayKey = $language.'|'.$displayName;
            if (isset($seenDisplayNames[$displayKey])) {
                throw new \RuntimeException("Duplicate display name detected for language '{$language}': {$displayName}");
            }
            $seenDisplayNames[$displayKey] = true;

            if (! str_starts_with($id, $language.'-')) {
                throw new \RuntimeException("Voice language mismatch in VoiceSeeder: {$id} for language {$language}");
            }

            $engineToken = match ($tier) {
                'standard' => '-standard-',
                'hd' => '-wavenet-',
                'hd_plus' => '-chirp3-hd-',
                default => throw new \RuntimeException("Unknown tier in VoiceSeeder: {$tier}"),
            };

            if (! str_contains($id, $engineToken)) {
                throw new \RuntimeException("Tier/engine mismatch in VoiceSeeder for {$id} at tier {$tier}");
            }
        }
    }
}

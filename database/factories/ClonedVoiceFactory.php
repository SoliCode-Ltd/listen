<?php

namespace Database\Factories;

use App\Models\ClonedVoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClonedVoiceFactory extends Factory
{
    protected $model = ClonedVoice::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true) . ' Voice',
            'source_type' => $this->faker->randomElement(['upload', 'recording']),
            'source_audio_path' => 'voice-cloning/test/' . $this->faker->uuid() . '.wav',
            'source_audio_duration_seconds' => $this->faker->numberBetween(10, 30),
            'language_code' => $this->faker->randomElement(['ar-XA', 'en-US']),
            'provider' => 'google_cloud_chirp3',
            'status' => 'pending',
            'consent_confirmed' => true,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => 'ready',
            'provider_voice_key' => 'test_cloning_key_' . $this->faker->uuid(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'failure_reason' => 'Test failure reason',
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}

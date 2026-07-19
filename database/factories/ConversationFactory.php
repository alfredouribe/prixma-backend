<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'user_id_1' => User::factory()->withCompletedOnboarding(),
            'user_id_2' => User::factory()->withCompletedOnboarding(),
            'type' => 'match',
            'status' => 'active',
            'match_id' => null,
        ];
    }

    /**
     * Fija `user_id_1`/`user_id_2` respetando la regla de orden (UUID menor
     * primero) usada en toda la app (MatchingService, SafetyService).
     */
    public function betweenUsers(User $userA, User $userB): static
    {
        [$id1, $id2] = $userA->id < $userB->id
            ? [$userA->id, $userB->id]
            : [$userB->id, $userA->id];

        return $this->state([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
        ]);
    }

    public function request(): static
    {
        return $this->state([
            'type' => 'request',
            'status' => 'pending',
            'match_id' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'blocked']);
    }

    public function rejected(): static
    {
        return $this->state(['type' => 'request', 'status' => 'rejected']);
    }
}

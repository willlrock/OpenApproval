<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state. Matches the real `users` schema
     * (phone-based identity — no email/name columns).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('+99899#######'),
            'password' => static::$password ??= Hash::make('password'),
            'lang' => 'ru',
            'role_id' => Role::firstOrCreate(['name' => 'requester'])->id,
            'account_status' => 'approved',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Assign a platform role by name (creates the Role row if missing).
     */
    public function role(string $name): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstOrCreate(['name' => $name])->id,
        ]);
    }

    /**
     * Tracker Admin = platform superadmin (§5.1).
     */
    public function trackerAdmin(): static
    {
        return $this->role('superadmin');
    }
}

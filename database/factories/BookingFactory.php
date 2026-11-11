<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(10, 0);

        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'title' => fake()->sentence(3),
        ];
    }

    /**
     * Время в ISO 8601 с любым смещением, в базу уходит UTC.
     *
     * Строку со смещением нельзя отдать модели напрямую: каст `datetime` отбросит смещение.
     */
    public function between(string $startsAt, string $endsAt): static
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc(),
        ]);
    }
}

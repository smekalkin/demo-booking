<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoomTodayBookingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRoomIds(): array
    {
        return [
            'не число' => ['abc'],
            'больше bigint' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('invalidRoomIds')]
    public function test_returns_not_found_for_invalid_room_id(string $roomId): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/rooms/{$roomId}/bookings/today")->assertNotFound();
    }
}

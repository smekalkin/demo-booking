<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_cancels_own_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs($booking->user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertModelMissing($booking);
    }

    public function test_cannot_cancel_booking_of_another_user(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertModelExists($booking);
    }

    public function test_returns_not_found_for_missing_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertModelExists($booking);
    }
}

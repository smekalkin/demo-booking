<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingOverlappingScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Существующая бронь — 10:00–11:00 по Москве.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function intervals(): array
    {
        return [
            'тот же интервал' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00', true],
            'частично в начале' => ['2026-11-02T09:30:00+03:00', '2026-11-02T10:15:00+03:00', true],
            'частично в конце' => ['2026-11-02T10:45:00+03:00', '2026-11-02T11:30:00+03:00', true],
            'внутри' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00', true],
            'накрывает' => ['2026-11-02T09:00:00+03:00', '2026-11-02T12:00:00+03:00', true],
            'границы в чужом поясе' => ['2026-11-02T14:30:00+07:00', '2026-11-02T15:30:00+07:00', true],
            'впритык после' => ['2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00', false],
            'впритык до' => ['2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00', false],
            'в другой день' => ['2026-11-03T10:00:00+03:00', '2026-11-03T11:00:00+03:00', false],
        ];
    }

    #[DataProvider('intervals')]
    public function test_finds_bookings_overlapping_half_open_interval(string $startsAt, string $endsAt, bool $isOverlapping): void
    {
        $room = Room::factory()->create();
        Booking::factory()->for($room)->between('2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')->create();

        $hasOverlap = Booking::query()
            ->where('room_id', $room->id)
            ->overlapping(CarbonImmutable::parse($startsAt), CarbonImmutable::parse($endsAt))
            ->exists();

        $this->assertSame($isOverlapping, $hasOverlap);
    }
}

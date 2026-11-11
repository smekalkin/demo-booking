<?php

namespace Tests\Feature;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $moscowRoom;

    private Room $novosibirskRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->moscowRoom = Room::factory()->for(Office::factory())->create();
        $this->novosibirskRoom = Room::factory()->for(Office::factory()->novosibirsk())->create();

        Sanctum::actingAs($this->user);
    }

    public function test_creates_booking_for_authenticated_user(): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated()
            ->assertJsonPath('room_id', $this->moscowRoom->id)
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('title', 'Планёрка')
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_ignores_user_id_from_request(): void
    {
        $anotherUser = User::factory()->create();

        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00', [
            'user_id' => $anotherUser->id,
        ])->assertCreated()->assertJsonPath('user_id', $this->user->id);

        $this->assertDatabaseMissing('bookings', ['user_id' => $anotherUser->id]);
    }

    /**
     * Одно и то же местное время 10:00–11:00 в Москве, записанное с разными смещениями.
     *
     * @return array<string, array{string, string}>
     */
    public static function sameMoscowSlotInDifferentOffsets(): array
    {
        return [
            'московское смещение' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00'],
            'UTC с Z' => ['2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z'],
            'UTC с +00:00' => ['2026-11-02T07:00:00+00:00', '2026-11-02T08:00:00+00:00'],
            'новосибирское смещение' => ['2026-11-02T14:00:00+07:00', '2026-11-02T15:00:00+07:00'],
            'отрицательное смещение' => ['2026-11-02T02:00:00-05:00', '2026-11-02T03:00:00-05:00'],
            'без секунд' => ['2026-11-02T10:00+03:00', '2026-11-02T11:00+03:00'],
            'с миллисекундами, как toISOString' => ['2026-11-02T07:00:00.000Z', '2026-11-02T08:00:00.000Z'],
            'с микросекундами, как в ответе API' => ['2026-11-02T07:00:00.000000Z', '2026-11-02T08:00:00.000000Z'],
            'крайнее смещение +14:00' => ['2026-11-02T21:00:00+14:00', '2026-11-02T22:00:00+14:00'],
        ];
    }

    #[DataProvider('sameMoscowSlotInDifferentOffsets')]
    public function test_accepts_iso_8601_with_any_offset_and_stores_utc(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingSlots(): array
    {
        return [
            'тот же слот' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00'],
            'наезжает на начало' => ['2026-11-02T09:30:00+03:00', '2026-11-02T10:15:00+03:00'],
            'наезжает на конец' => ['2026-11-02T10:45:00+03:00', '2026-11-02T11:30:00+03:00'],
            'внутри существующей' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'накрывает существующую' => ['2026-11-02T09:00:00+03:00', '2026-11-02T12:00:00+03:00'],
            'то же время с другим смещением' => ['2026-11-02T14:30:00+07:00', '2026-11-02T15:30:00+07:00'],
        ];
    }

    #[DataProvider('overlappingSlots')]
    public function test_rejects_overlapping_booking_in_same_room(string $startsAt, string $endsAt): void
    {
        $this->bookMoscowRoomFromTenToEleven();

        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => StoreBookingRequest::ROOM_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adjacentSlots(): array
    {
        return [
            'сразу после' => ['2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00'],
            'сразу до' => ['2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00'],
            'сразу после, другое смещение' => ['2026-11-02T08:00:00Z', '2026-11-02T09:00:00Z'],
        ];
    }

    #[DataProvider('adjacentSlots')]
    public function test_allows_adjacent_booking(string $startsAt, string $endsAt): void
    {
        $this->bookMoscowRoomFromTenToEleven();

        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_allows_same_slot_in_another_room(): void
    {
        $this->bookMoscowRoomFromTenToEleven();
        $anotherMoscowRoom = Room::factory()->for($this->moscowRoom->office)->create();

        $this->postBooking($anotherMoscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedEndTimes(): array
    {
        return [
            'минимум 15 минут' => ['2026-11-02T10:15:00+03:00'],
            'шаг 15 минут' => ['2026-11-02T11:45:00+03:00'],
            'максимум 4 часа' => ['2026-11-02T14:00:00+03:00'],
        ];
    }

    #[DataProvider('allowedEndTimes')]
    public function test_accepts_duration_from_15_minutes_to_4_hours(string $endsAt): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', $endsAt)->assertCreated();
    }

    public function test_rejects_booking_shorter_than_15_minutes(): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T10:10:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Длительность брони — от 15 минут до 4 часов.']);
    }

    public function test_rejects_booking_longer_than_4_hours(): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Длительность брони — от 15 минут до 4 часов.']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endTimesNotAfterStart(): array
    {
        return [
            'конец равен началу' => ['2026-11-02T10:00:00+03:00'],
            'конец раньше начала' => ['2026-11-02T09:00:00+03:00'],
        ];
    }

    #[DataProvider('endTimesNotAfterStart')]
    public function test_rejects_end_not_after_start(string $endsAt): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', $endsAt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Конец брони должен быть позже начала.']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function slotsOffGrid(): array
    {
        return [
            'начало в 10:05' => ['2026-11-02T10:05:00+03:00', '2026-11-02T11:00:00+03:00', 'starts_at'],
            'начало с секундами' => ['2026-11-02T10:00:30+03:00', '2026-11-02T11:00:00+03:00', 'starts_at'],
            'начало с миллисекундами' => ['2026-11-02T07:00:00.500Z', '2026-11-02T08:00:00.000Z', 'starts_at'],
            'конец в 10:50' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:50:00+03:00', 'ends_at'],
        ];
    }

    #[DataProvider('slotsOffGrid')]
    public function test_rejects_time_off_15_minute_grid(string $startsAt, string $endsAt, string $invalidField): void
    {
        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$invalidField]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function slotsWithinMoscowWorkingHours(): array
    {
        return [
            'с самого начала дня' => ['2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00'],
            'до самого конца дня' => ['2026-11-02T19:00:00+03:00', '2026-11-02T20:00:00+03:00'],
            '20:00 по Новосибирску — это 16:00 в Москве' => ['2026-11-02T19:00:00+07:00', '2026-11-02T20:00:00+07:00'],
        ];
    }

    #[DataProvider('slotsWithinMoscowWorkingHours')]
    public function test_accepts_booking_within_working_hours_of_moscow_office(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)->assertCreated();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function slotsOutsideMoscowWorkingHours(): array
    {
        return [
            'начинается до 09:00' => ['2026-11-02T08:45:00+03:00', '2026-11-02T09:30:00+03:00'],
            'заканчивается после 20:00' => ['2026-11-02T19:30:00+03:00', '2026-11-02T20:15:00+03:00'],
            'целиком вечером' => ['2026-11-02T20:00:00+03:00', '2026-11-02T21:00:00+03:00'],
            'через полночь' => ['2026-11-02T23:00:00+03:00', '2026-11-03T01:00:00+03:00'],
            '10:00 по Новосибирску — это 06:00 в Москве' => ['2026-11-02T10:00:00+07:00', '2026-11-02T11:00:00+07:00'],
        ];
    }

    #[DataProvider('slotsOutsideMoscowWorkingHours')]
    public function test_rejects_booking_outside_working_hours_of_moscow_office(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->moscowRoom, $startsAt, $endsAt)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_checks_working_hours_in_novosibirsk_office_timezone(): void
    {
        $this->postBooking($this->novosibirskRoom, '2026-11-02T09:00:00+07:00', '2026-11-02T10:00:00+07:00')
            ->assertCreated();

        $this->postBooking($this->novosibirskRoom, '2026-11-02T06:00:00+03:00', '2026-11-02T07:00:00+03:00')
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-11-02T03:00:00.000000Z');

        $this->postBooking($this->novosibirskRoom, '2026-11-02T18:00:00+03:00', '2026-11-02T19:00:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at' => 'Бронь должна укладываться в рабочее время 09:00–20:00 по времени офиса (Asia/Novosibirsk): у вас 22:00–23:00.',
            ]);
    }

    public function test_requires_all_fields(): void
    {
        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'несуществующая комната' => [['room_id' => 999_999], 'room_id'],
            'комната строкой' => [['room_id' => '1 OR 1=1 --'], 'room_id'],
            'пустое название' => [['title' => ''], 'title'],
            'слишком длинное название' => [['title' => str_repeat('а', 256)], 'title'],
            'время без смещения' => [['starts_at' => '2026-11-02T10:00:00'], 'starts_at'],
            'несуществующее смещение' => [['starts_at' => '2026-11-02T10:00:00+25:00'], 'starts_at'],
            'смещение больше +14:00' => [['starts_at' => '2026-11-02T10:00:00+14:30'], 'starts_at'],
            'NUL в названии' => [['title' => "План\0ёрка"], 'title'],
            'управляющий символ в названии' => [['title' => "План\x07ёрка"], 'title'],
            'не ISO 8601' => [['starts_at' => '02.11.2026 10:00'], 'starts_at'],
            'несуществующая дата' => [['ends_at' => '2026-02-30T11:00:00+03:00'], 'ends_at'],
            'время массивом' => [['ends_at' => ['2026-11-02T11:00:00+03:00']], 'ends_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $invalidField
     */
    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_field(array $invalidField, string $fieldName): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00', $invalidField)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$fieldName]);
    }

    /**
     * `true` без отдельной проверки превратился бы в комнату 1. Проверяем текст: «Комната не найдена» здесь был бы ложным успехом.
     */
    public function test_rejects_boolean_room_id(): void
    {
        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00', ['room_id' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id' => 'Укажите id комнаты числом.']);
    }

    /**
     * Битый UTF-8 приходит только в form-запросе: JSON с ним не разобрать.
     */
    public function test_rejects_title_with_invalid_utf8(): void
    {
        $this->post('/api/bookings', [
            'room_id' => $this->moscowRoom->id,
            'title' => "\xFF\xFE",
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'Название содержит недопустимые символы.']);
    }

    public function test_allows_booking_in_the_past(): void
    {
        $this->postBooking($this->moscowRoom, '2020-01-10T10:00:00+03:00', '2020-01-10T11:00:00+03:00')
            ->assertCreated();
    }

    /**
     * Ограничение в базе вернуло бы ту же 422, поэтому смотрим, что до INSERT дело не дошло.
     * `beforeExecuting`, а не `DB::listen`: неудачный INSERT в `listen` не попадает.
     */
    public function test_rejects_overlap_before_inserting(): void
    {
        $this->bookMoscowRoomFromTenToEleven();

        $bookingInserts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$bookingInserts): void {
            if (str_starts_with($sql, 'insert into "bookings"')) {
                $bookingInserts++;
            }
        });

        $this->postBooking($this->moscowRoom, '2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00')
            ->assertUnprocessable();

        $this->assertSame(0, $bookingInserts, 'Пересечение должна отсекать валидация, а не ограничение в базе.');
    }

    /**
     * Вставки в комнату сериализуются блокировкой её строки, иначе одновременные запросы ловят deadlock.
     */
    public function test_locks_room_before_inserting_booking(): void
    {
        $statements = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$statements): void {
            $statements[] = $sql;
        });

        $this->postBooking($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $roomLock = array_find_key($statements, fn (string $sql): bool => str_starts_with($sql, 'select * from "rooms"') && str_ends_with($sql, 'for update'));
        $bookingInsert = array_find_key($statements, fn (string $sql): bool => str_starts_with($sql, 'insert into "bookings"'));

        $this->assertNotNull($roomLock, 'Строка комнаты не блокируется.');
        $this->assertNotNull($bookingInsert);
        $this->assertLessThan($bookingInsert, $roomLock);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/bookings', [])->assertUnauthorized();
    }

    /**
     * Параллельный запрос занимает слот после проверки пересечений, но до вставки.
     */
    public function test_returns_validation_error_when_concurrent_request_takes_slot(): void
    {
        $this->app->afterResolving(StoreBookingRequest::class, function (): void {
            $this->bookMoscowRoomFromTenToEleven();
        });

        $this->postBooking($this->moscowRoom, '2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => StoreBookingRequest::ROOM_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_database_rejects_overlapping_bookings_of_same_room(): void
    {
        $this->bookMoscowRoomFromTenToEleven();

        try {
            DB::transaction(fn () => Booking::factory()
                ->for($this->moscowRoom)
                ->between('2026-11-02T10:45:00+03:00', '2026-11-02T11:15:00+03:00')
                ->create());

            $this->fail('Ограничение в базе пропустило пересекающуюся бронь.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
        }

        Booking::factory()
            ->for($this->moscowRoom)
            ->between('2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00')
            ->create();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * Даты уходят в базу строкой без смещения и читаются в поясе сессии, поэтому он должен быть UTC.
     * Без настройки `timezone` в конфиге здесь был бы `Etc/UTC` из образа PostgreSQL или любой пояс сервера.
     */
    public function test_database_session_timezone_is_pinned_to_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
    }

    private function bookMoscowRoomFromTenToEleven(): Booking
    {
        return Booking::factory()
            ->for($this->moscowRoom)
            ->between('2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBooking(Room $room, string $startsAt, string $endsAt, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...$overrides,
        ]);
    }
}

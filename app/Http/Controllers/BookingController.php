<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * SQLSTATE нарушения ограничения-исключения `bookings_room_time_no_overlap`.
     *
     * Срабатывает, когда параллельный запрос занял слот между проверкой в {@see StoreBookingRequest} и вставкой.
     */
    private const EXCLUSION_VIOLATION = '23P01';

    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * Правила брони проверяет {@see StoreBookingRequest}, ограничение в базе страхует от гонки.
     *
     * Вставки в одну комнату идут по очереди: транзакция сначала блокирует строку комнаты.
     * Без блокировки одновременные вставки пересекающихся броней ждут друг друга на проверке ограничения,
     * и PostgreSQL прерывает часть из них с deadlock (500). С блокировкой следующая вставка видит уже
     * закоммиченную бронь и сразу получает нарушение ограничения, то есть 422.
     *
     * Внутри внешней транзакции (например, в тестах) это точка сохранения, и ошибка ограничения не ломает внешнюю.
     */
    public function store(StoreBookingRequest $request): Booking
    {
        $room = $request->room();

        try {
            $booking = DB::transaction(function () use ($request, $room): Booking {
                Room::query()->whereKey($room->id)->lockForUpdate()->first();

                return $request->user()->bookings()->create([
                    'room_id' => $room->id,
                    'title' => $request->validated('title'),
                    'starts_at' => $request->startsAt(),
                    'ends_at' => $request->endsAt(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::EXCLUSION_VIOLATION) {
                throw ValidationException::withMessages(['starts_at' => StoreBookingRequest::ROOM_TAKEN_MESSAGE]);
            }

            throw $exception;
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
            'user_id' => $booking->user_id,
        ]);

        return $booking;
    }

    public function destroy(Booking $booking): Response
    {
        Gate::authorize('delete', $booking);

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}

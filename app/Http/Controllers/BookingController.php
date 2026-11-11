<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    private const INSERT_ATTEMPTS = 3;

    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * Правила брони проверяет {@see StoreBookingRequest}, ограничение в базе страхует от гонки.
     *
     * Вставка обёрнута в транзакцию по двум причинам:
     * - одновременные вставки пересекающихся броней могут заблокировать друг друга на проверке ограничения,
     *   PostgreSQL прервёт одну из них с deadlock; Laravel повторит транзакцию, и повтор получит обычное нарушение ограничения;
     * - внутри внешней транзакции (например, в тестах) это точка сохранения, и ошибка ограничения не ломает внешнюю.
     */
    public function store(StoreBookingRequest $request): Booking
    {
        try {
            $booking = DB::transaction(fn (): Booking => $request->user()->bookings()->create([
                'room_id' => $request->room()->id,
                'title' => $request->validated('title'),
                'starts_at' => $request->startsAt(),
                'ends_at' => $request->endsAt(),
            ]), attempts: self::INSERT_ATTEMPTS);
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

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

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

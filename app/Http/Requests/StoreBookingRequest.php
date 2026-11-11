<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public const SLOT_STEP_MINUTES = 15;

    public const MIN_DURATION_MINUTES = 15;

    public const MAX_DURATION_MINUTES = 240;

    public const WORKDAY_STARTS_AT = '09:00';

    public const WORKDAY_ENDS_AT = '20:00';

    public const ROOM_TAKEN_MESSAGE = 'Комната уже занята в это время.';

    /**
     * ISO 8601 с обязательным смещением: «Z» или «±hh:mm», с секундами или без, с долями секунды:
     * миллисекунды (как у JS toISOString) и микросекунды (как в ответах самого API).
     *
     * `date_format` сверяет значение с повторным форматированием, поэтому «Z» (формат `p`) и «+00:00» (формат `P`) перечислены отдельно.
     *
     * @var list<string>
     */
    private const ISO_8601_FORMATS = [
        'Y-m-d\TH:iP',
        'Y-m-d\TH:ip',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:sp',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:s.vp',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s.up',
    ];

    /**
     * Смещение от -14:00 до +14:00 (реальные — от -12:00 до +14:00). `date_format` пропускает и «+25:00», а Carbon такое не разбирает.
     */
    private const UTC_OFFSET_PATTERN = '/(Z|[+-](0\d|1[0-3]):[0-5]\d|[+-]14:00)$/';

    /**
     * Корректный UTF-8 без управляющих символов. Флаг `u` проваливает проверку на битом UTF-8 (иначе 500 от PostgreSQL),
     * а NUL PostgreSQL молча обрезает: в ответе было бы одно название, в базе — другое.
     */
    private const PRINTABLE_TEXT_PATTERN = '/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u';

    private ?Room $resolvedRoom = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `numeric` рядом с `integer` отсекает `true`: `integer` проверяет через `filter_var`, а тот считает `true` единицей.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dateTimeRules = [
            'bail',
            'required',
            'string',
            'date_format:'.implode(',', self::ISO_8601_FORMATS),
            'regex:'.self::UTC_OFFSET_PATTERN,
        ];

        return [
            'room_id' => ['bail', 'required', 'numeric', 'integer', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'regex:'.self::PRINTABLE_TEXT_PATTERN, 'max:255'],
            'starts_at' => $dateTimeRules,
            'ends_at' => $dateTimeRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Поле обязательно.',
            'room_id.numeric' => 'Укажите id комнаты числом.',
            'room_id.integer' => 'Укажите id комнаты числом.',
            'room_id.exists' => 'Комната не найдена.',
            'title.string' => 'Название должно быть строкой.',
            'title.regex' => 'Название содержит недопустимые символы.',
            'title.max' => 'Название не длиннее :max символов.',
            'starts_at.string' => 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.',
            'starts_at.date_format' => 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.',
            'ends_at.string' => 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T11:00:00+03:00.',
            'ends_at.date_format' => 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T11:00:00+03:00.',
            'starts_at.regex' => 'Смещение часового пояса должно быть от -14:00 до +14:00.',
            'ends_at.regex' => 'Смещение часового пояса должно быть от -14:00 до +14:00.',
        ];
    }

    /**
     * Бизнес-правила проверяются, только если поля прошли базовую валидацию: им нужны комната и корректные даты.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateSlot($validator);
            },
        ];
    }

    public function room(): Room
    {
        return $this->resolvedRoom ??= Room::with('office')->findOrFail($this->integer('room_id'));
    }

    /**
     * Начало брони в UTC.
     *
     * Именно UTC, а не исходное смещение: Eloquent и биндинги запросов форматируют дату как `Y-m-d H:i:s`
     * без смещения, и база прочитает это время в поясе сессии (UTC).
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('starts_at'))->utc();
    }

    /**
     * Конец брони в UTC, см. {@see self::startsAt()}.
     */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('ends_at'))->utc();
    }

    /**
     * Проверки идут от простых к дорогим: длительность и сетка не требуют базы, пересечение — требует.
     * Длительность — раньше сетки, чтобы 10:00–10:10 получило ошибку про минимум 15 минут, а не про сетку.
     * Достаточно первой ошибки: одна бронь редко нарушает несколько правил, а клиенту нужна одна понятная причина.
     *
     * Год 0000 формат пропускает, а в PostgreSQL нулевого года нет. Проверяется год в UTC: 0001-01-01T00:00+03:00 — это уже 0000 год.
     */
    private function validateSlot(Validator $validator): void
    {
        if ($this->startsAt()->year < 1) {
            $validator->errors()->add('starts_at', 'Бронь не может начинаться раньше 0001 года.');

            return;
        }

        $room = $this->room();
        $timezone = $room->office->timezone;
        $startsAt = $this->startsAt()->setTimezone($timezone);
        $endsAt = $this->endsAt()->setTimezone($timezone);

        if ($endsAt <= $startsAt) {
            $validator->errors()->add('ends_at', 'Конец брони должен быть позже начала.');

            return;
        }

        $durationInMinutes = intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60);

        if ($durationInMinutes < self::MIN_DURATION_MINUTES || $durationInMinutes > self::MAX_DURATION_MINUTES) {
            $validator->errors()->add('ends_at', 'Длительность брони — от 15 минут до 4 часов.');

            return;
        }

        if (! $this->isOnSlotGrid($startsAt)) {
            $validator->errors()->add('starts_at', 'Начало брони должно быть кратно '.self::SLOT_STEP_MINUTES.' минутам: 10:00, 10:15, 10:30…');

            return;
        }

        if (! $this->isOnSlotGrid($endsAt)) {
            $validator->errors()->add('ends_at', 'Конец брони должен быть кратен '.self::SLOT_STEP_MINUTES.' минутам: 10:00, 10:15, 10:30…');

            return;
        }

        if (! $this->isWithinWorkingHours($startsAt, $endsAt)) {
            $validator->errors()->add('starts_at', sprintf(
                'Бронь должна укладываться в рабочее время %s–%s по времени офиса (%s): у вас %s–%s.',
                self::WORKDAY_STARTS_AT,
                self::WORKDAY_ENDS_AT,
                $timezone,
                $startsAt->format('H:i'),
                $endsAt->format('H:i'),
            ));

            return;
        }

        if (Booking::query()->where('room_id', $room->id)->overlapping($startsAt, $endsAt)->exists()) {
            $validator->errors()->add('starts_at', self::ROOM_TAKEN_MESSAGE);
        }
    }

    private function isOnSlotGrid(CarbonImmutable $localTime): bool
    {
        return $localTime->minute % self::SLOT_STEP_MINUTES === 0
            && $localTime->second === 0
            && $localTime->microsecond === 0;
    }

    /**
     * Обе границы — в местном времени офиса и в пределах одного рабочего дня.
     */
    private function isWithinWorkingHours(CarbonImmutable $localStartsAt, CarbonImmutable $localEndsAt): bool
    {
        $workdayStartsAt = $localStartsAt->setTimeFromTimeString(self::WORKDAY_STARTS_AT);
        $workdayEndsAt = $localStartsAt->setTimeFromTimeString(self::WORKDAY_ENDS_AT);

        return $localStartsAt >= $workdayStartsAt && $localEndsAt <= $workdayEndsAt;
    }
}

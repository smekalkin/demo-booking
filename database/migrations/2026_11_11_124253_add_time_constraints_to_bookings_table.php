<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Время брони хранится как момент времени (timestamptz), а не как «часы на стене» без пояса.
 *
 * Пересечения броней одной комнаты запрещены на уровне базы ограничением-исключением:
 * полуинтервал [starts_at, ends_at) не включает правую границу, поэтому встречи «впритык» допустимы.
 * Ограничение закрывает и гонку двух одновременных запросов на один слот.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement("ALTER TABLE bookings
            ALTER COLUMN starts_at TYPE timestamptz(0) USING starts_at AT TIME ZONE 'UTC',
            ALTER COLUMN ends_at TYPE timestamptz(0) USING ends_at AT TIME ZONE 'UTC'");

        DB::statement('ALTER TABLE bookings
            ADD CONSTRAINT bookings_ends_after_starts CHECK (ends_at > starts_at)');

        DB::statement("ALTER TABLE bookings
            ADD CONSTRAINT bookings_room_time_no_overlap
            EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_room_time_no_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_ends_after_starts');

        DB::statement("ALTER TABLE bookings
            ALTER COLUMN starts_at TYPE timestamp(0) USING starts_at AT TIME ZONE 'UTC',
            ALTER COLUMN ends_at TYPE timestamp(0) USING ends_at AT TIME ZONE 'UTC'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One physical room can never be held by two bookings on the same night
 * (spec 020 FR-009), whatever code writes the rows.
 *
 * `held_nights` is the line's nights while it holds its room, NULL otherwise.
 * It depends on the line, its reservation and its stay, and at least six code
 * paths change those, so triggers keep it, not PHP (research R1): no PHP code
 * ever writes it. The exclusion constraint then rejects two held lines of one
 * room whose nights overlap; the departure day is not a night, so back-to-back
 * bookings fit. The rule matches RoomAssignmentRules::holdingLines().
 */
return new class extends Migration
{
    public function up(): void
    {
        $overlaps = DB::selectOne(<<<'SQL'
            WITH held AS (
                SELECT rr.id, rr.room_id, r.arrival_date, r.departure_date
                FROM reservation_rooms rr
                JOIN reservations r ON r.id = rr.reservation_id
                WHERE rr.room_id IS NOT NULL
                  AND rr.status <> 'cancelled' AND rr.deleted_at IS NULL
                  AND r.deleted_at IS NULL AND r.status IN ('pending', 'confirmed', 'checked_in')
                  AND NOT EXISTS (
                      SELECT 1 FROM stays s
                      WHERE s.reservation_room_id = rr.id AND s.deleted_at IS NULL
                        AND s.status IN ('departed', 'no_show'))
            )
            SELECT count(*) AS total FROM held a
            JOIN held b ON a.room_id = b.room_id AND a.id < b.id
             AND a.arrival_date < b.departure_date AND b.arrival_date < a.departure_date
            SQL)->total;

        if ($overlaps > 0) {
            throw new RuntimeException("{$overlaps} overlapping room assignments exist; run php artisan rooms:overlaps and resolve them before migrating.");
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('ALTER TABLE reservation_rooms ADD COLUMN held_nights daterange NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reservation_rooms_set_held_nights() RETURNS trigger AS $$
            DECLARE
                booking record;
            BEGIN
                NEW.held_nights := NULL;

                IF NEW.room_id IS NULL OR NEW.status = 'cancelled' OR NEW.deleted_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                SELECT arrival_date, departure_date INTO booking FROM reservations
                WHERE id = NEW.reservation_id AND deleted_at IS NULL
                  AND status IN ('pending', 'confirmed', 'checked_in');

                IF NOT FOUND OR booking.departure_date <= booking.arrival_date THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1 FROM stays
                    WHERE reservation_room_id = NEW.id AND deleted_at IS NULL
                      AND status IN ('departed', 'no_show')
                ) THEN
                    RETURN NEW;
                END IF;

                NEW.held_nights := daterange(booking.arrival_date, booking.departure_date, '[)');

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER reservation_rooms_held_nights
                BEFORE INSERT OR UPDATE ON reservation_rooms
                FOR EACH ROW EXECUTE FUNCTION reservation_rooms_set_held_nights();

            -- A reservation's dates or status decide its lines' nights: touch
            -- them so the trigger above recomputes.
            CREATE OR REPLACE FUNCTION reservations_refresh_held_nights() RETURNS trigger AS $$
            BEGIN
                UPDATE reservation_rooms SET held_nights = NULL WHERE reservation_id = NEW.id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER reservations_held_nights
                AFTER UPDATE OF arrival_date, departure_date, status, deleted_at ON reservations
                FOR EACH ROW
                WHEN (OLD.arrival_date IS DISTINCT FROM NEW.arrival_date
                   OR OLD.departure_date IS DISTINCT FROM NEW.departure_date
                   OR OLD.status IS DISTINCT FROM NEW.status
                   OR OLD.deleted_at IS DISTINCT FROM NEW.deleted_at)
                EXECUTE FUNCTION reservations_refresh_held_nights();

            -- A stay that departs or never came releases its line's room.
            CREATE OR REPLACE FUNCTION stays_refresh_held_nights() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'INSERT' AND OLD.reservation_room_id IS NOT NULL THEN
                    UPDATE reservation_rooms SET held_nights = NULL WHERE id = OLD.reservation_room_id;
                END IF;

                IF TG_OP <> 'DELETE' AND NEW.reservation_room_id IS NOT NULL THEN
                    UPDATE reservation_rooms SET held_nights = NULL WHERE id = NEW.reservation_room_id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stays_held_nights
                AFTER INSERT OR UPDATE OF status, deleted_at, reservation_room_id OR DELETE ON stays
                FOR EACH ROW EXECUTE FUNCTION stays_refresh_held_nights();
            SQL);

        DB::statement('UPDATE reservation_rooms SET held_nights = NULL');

        DB::statement(
            'ALTER TABLE reservation_rooms ADD CONSTRAINT reservation_rooms_no_overlap '
            .'EXCLUDE USING gist (room_id WITH =, held_nights WITH &&) WHERE (held_nights IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservation_rooms DROP CONSTRAINT IF EXISTS reservation_rooms_no_overlap');
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stays_held_nights ON stays;
            DROP TRIGGER IF EXISTS reservations_held_nights ON reservations;
            DROP TRIGGER IF EXISTS reservation_rooms_held_nights ON reservation_rooms;
            DROP FUNCTION IF EXISTS stays_refresh_held_nights();
            DROP FUNCTION IF EXISTS reservations_refresh_held_nights();
            DROP FUNCTION IF EXISTS reservation_rooms_set_held_nights();
            SQL);
        DB::statement('ALTER TABLE reservation_rooms DROP COLUMN IF EXISTS held_nights');
    }
};

<?php

namespace App\Support\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Validation\ValidationException;

/**
 * The reservation lifecycle (SPEC-012 FR-001): which reservation status may
 * follow which, and through which operation. Every other change is refused.
 *
 * Who asks:
 * - the edit path (ReservationCreator) — assertEdit(), and assertCreate();
 * - NoShowService — allows() for a whole-reservation no-show and its undo.
 * The check-in and check-out rows describe StayLifecycleService's moves; that
 * service keeps its own, stricter per-room rules (SPEC-024/025), and a change
 * to those rows must be mirrored there. Room-level no-shows change a room,
 * not the reservation, until the last live room is one. The reservation
 * import records past statuses as they were and never asks.
 */
class ReservationLifecycle
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    public const CHECK_IN = 'check_in';

    public const CHECK_OUT = 'check_out';

    public const NO_SHOW = 'no_show';

    public const UNDO_NO_SHOW = 'undo_no_show';

    /**
     * from → to → the operations that may make that change. `new` is a
     * reservation being created.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const TABLE = [
        'new' => [
            'pending' => [self::CREATE],
            'confirmed' => [self::CREATE],
        ],
        'pending' => [
            'confirmed' => [self::EDIT],
            'cancelled' => [self::EDIT],
            'no_show' => [self::NO_SHOW],
        ],
        'confirmed' => [
            'pending' => [self::EDIT],
            'cancelled' => [self::EDIT],
            'checked_in' => [self::CHECK_IN],
            'no_show' => [self::NO_SHOW],
        ],
        'cancelled' => [
            'pending' => [self::EDIT],
            'confirmed' => [self::EDIT],
        ],
        'checked_in' => [
            'checked_out' => [self::CHECK_OUT],
        ],
        'no_show' => [
            'pending' => [self::UNDO_NO_SHOW],
            'confirmed' => [self::UNDO_NO_SHOW],
        ],
    ];

    public static function allows(?ReservationStatus $from, ReservationStatus $to, string $operation): bool
    {
        return in_array($operation, self::TABLE[$from->value ?? 'new'][$to->value] ?? [], true);
    }

    /**
     * Refuses a status an edit may not set (FR-001, FR-003). Leaving the
     * status as it is, is not a change.
     *
     * @throws ValidationException on `status`
     */
    public static function assertEdit(Reservation $reservation, ReservationStatus $to): void
    {
        $from = $reservation->status;

        if ($from === $to) {
            return;
        }

        if ($to === ReservationStatus::NO_SHOW) {
            throw ValidationException::withMessages(['status' => "Use POST /reservation/{$reservation->id}/no-show."]);
        }

        if ($from === ReservationStatus::NO_SHOW) {
            throw ValidationException::withMessages(['status' => "Use POST /reservation/{$reservation->id}/undo-no-show."]);
        }

        // Leaving checked_in is check-out's: keep the message that says so.
        if ($from === ReservationStatus::CHECKED_IN) {
            throw ValidationException::withMessages(['status' => 'Check out the in-house rooms first.']);
        }

        if (! self::allows($from, $to, self::EDIT)) {
            throw ValidationException::withMessages(['status' => "A {$from->value} reservation can't be changed to {$to->value}."]);
        }
    }

    /**
     * Refuses a new reservation recorded as a no-show (FR-003). Which other
     * statuses a caller may create with stays with that caller: staff and the
     * AI create pending or confirmed (checking in through its own service),
     * while the import and test fixtures record past statuses as they were.
     *
     * @throws ValidationException on `status`
     */
    public static function assertCreate(ReservationStatus $status): void
    {
        if ($status === ReservationStatus::NO_SHOW) {
            throw ValidationException::withMessages(['status' => 'Mark a no-show with POST /reservation/{id}/no-show after creating the reservation.']);
        }
    }
}

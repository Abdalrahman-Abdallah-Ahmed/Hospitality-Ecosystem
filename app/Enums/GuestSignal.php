<?php

namespace App\Enums;

/**
 * Why a guest-related task exists. A different question from who created it
 * (tasks.created_by), which is why it is its own column.
 */
enum GuestSignal: string
{
    case ESCALATION = 'escalation';                 // EscalateToHumanTool
    case SERVICE_REQUEST = 'service_request';       // something is needed (housekeeping or other)
    case BOOKING_FOLLOW_UP = 'booking_follow_up';   // staff to help an interested guest book — a positive signal
    // RequestBookingCancellationTool. Not a complaint, so it does not pause pitching.
    case CANCELLATION_REQUEST = 'cancellation_request';
    case MAINTENANCE_REQUEST = 'maintenance_request';   // something in the room is broken; always the Maintenance team
    case ROOM_CHANGE_REQUEST = 'room_change_request';   // RequestRoomChangeTool: staff decide, never automatic

    /**
     * The kinds whose completion the guest is told about (SPEC-044, FR-009).
     * Escalations are not: staff have already spoken to the guest.
     */
    public function notifiesOnCompletion(): bool
    {
        return in_array($this, [self::SERVICE_REQUEST, self::MAINTENANCE_REQUEST, self::ROOM_CHANGE_REQUEST], true);
    }

    /**
     * Something is needed or wrong, so pitching stays quiet while it is open.
     */
    public function isComplaint(): bool
    {
        return in_array($this, [self::SERVICE_REQUEST, self::MAINTENANCE_REQUEST, self::ROOM_CHANGE_REQUEST], true);
    }

    /**
     * @return list<string>
     */
    public static function complaintValues(): array
    {
        return array_values(array_map(
            fn (self $signal) => $signal->value,
            array_filter(self::cases(), fn (self $signal) => $signal->isComplaint()),
        ));
    }
}

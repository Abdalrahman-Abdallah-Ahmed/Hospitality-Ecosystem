<?php

namespace App\Ai\Tools\Admin;

use App\Ai\Tools\AssignRoomsTool;
use App\Ai\Tools\CancelReservationTool;
use App\Ai\Tools\CheckInTool;
use App\Ai\Tools\CheckOutTool;
use App\Ai\Tools\CreateActivityBookingTool;
use App\Ai\Tools\CreateActivityTool;
use App\Ai\Tools\CreateGuestTool;
use App\Ai\Tools\CreateKnowledgeArticleTool;
use App\Ai\Tools\CreateReservationTool;
use App\Ai\Tools\CreateRoomTool;
use App\Ai\Tools\CreateTaskTool;
use App\Ai\Tools\DecideBookingCancellationTool;
use App\Ai\Tools\GetActivitiesTool;
use App\Ai\Tools\GetAvailabilityTool;
use App\Ai\Tools\GetBookingsTool;
use App\Ai\Tools\GetGuestMessagesTool;
use App\Ai\Tools\GetGuestsTool;
use App\Ai\Tools\GetGuestTool;
use App\Ai\Tools\GetHotelSettingsTool;
use App\Ai\Tools\GetHousekeepingBoardTool;
use App\Ai\Tools\GetMaintenanceTool;
use App\Ai\Tools\GetReportTool;
use App\Ai\Tools\GetReservationsTool;
use App\Ai\Tools\GetReservationTool;
use App\Ai\Tools\GetRoomsTool;
use App\Ai\Tools\GetRoomTypesTool;
use App\Ai\Tools\GetStaffTool;
use App\Ai\Tools\GetStaysTool;
use App\Ai\Tools\GetTaskCategoriesTool;
use App\Ai\Tools\GetTasksTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Ai\Tools\ReportTaskIssueTool;
use App\Ai\Tools\ReturnRoomToServiceTool;
use App\Ai\Tools\SetHousekeepingStatusTool;
use App\Ai\Tools\SetRoomOutOfOrderTool;
use App\Ai\Tools\UpdateBookingStatusTool;
use App\Ai\Tools\UpdateGuestTool;
use App\Ai\Tools\UpdateKnowledgeArticleTool;
use App\Ai\Tools\UpdateOutOfOrderTool;
use App\Ai\Tools\UpdateReservationTool;
use App\Ai\Tools\UpdateTaskTool;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;

/**
 * The single list of tools the Admin AI may call, each with the permission
 * the matching staff endpoint checks (contracts/admin-ai-tools.md). Adding a
 * tool means adding an entry here; the architecture test reads this list, so
 * a tool cannot reach the advisor without a declared permission, and a write
 * cannot reach it without naming the audited models it changes.
 *
 * Deliberately absent: deleting anything, and writing users, staff roles,
 * permissions, hotel settings, policies, documents, global knowledge or the
 * transaction ledger (FR-018, FR-019, FR-021, FR-022).
 */
class AdminToolset
{
    /**
     * @return list<GuardedTool>
     */
    public static function for(User $user, ?string $conversationId = null, string $locale = 'en'): array
    {
        $hotel = $user->hotel;

        return array_map(fn (array $entry) => new GuardedTool(
            inner: $entry['tool'],
            hotel: $hotel,
            user: $user,
            kind: $entry['kind'],
            permissions: $entry['permissions'] ?? [],
            adminOnly: $entry['adminOnly'] ?? false,
            selfChecked: $entry['selfChecked'] ?? false,
            writes: $entry['writes'] ?? [],
            conversationId: $conversationId,
            locale: $locale,
        ), self::entries($hotel, $user));
    }

    /**
     * @return list<array{tool: Tool, kind: string, permissions?: list<Permission>, adminOnly?: bool, selfChecked?: bool, writes?: list<class-string>}>
     */
    private static function entries(Hotel $hotel, User $user): array
    {
        return [
            // Read: operations.
            self::read(new KnowledgeSearchTool($hotel), Permission::KNOWLEDGE_BASE_ARTICLES_VIEW),
            self::read(new GetGuestsTool($hotel), Permission::GUESTS_VIEW),
            self::read(new GetGuestTool($hotel), Permission::GUESTS_VIEW),
            self::read(new GetGuestMessagesTool($hotel), Permission::GUESTS_VIEW),
            self::read(new GetReservationsTool($hotel, paged: true), Permission::RESERVATIONS_VIEW),
            self::read(new GetReservationTool($hotel), Permission::RESERVATIONS_VIEW),
            self::read(new GetRoomTypesTool($hotel), Permission::ROOM_TYPES_VIEW),
            self::read(new GetRoomsTool($hotel), Permission::ROOMS_VIEW),
            self::read(new GetAvailabilityTool($hotel, $user), Permission::AVAILABILITY_VIEW),
            self::read(new GetStaysTool($hotel, $user), Permission::STAYS_VIEW),
            self::read(new GetTasksTool($hotel, paged: true), Permission::TASKS_VIEW),
            self::read(new GetTaskCategoriesTool($hotel), Permission::TASK_CATEGORIES_VIEW),
            self::read(new GetHousekeepingBoardTool($hotel), Permission::ROOMS_VIEW),
            self::read(new GetMaintenanceTool($hotel), Permission::TASKS_VIEW),
            self::read(new GetActivitiesTool($hotel), Permission::ACTIVITIES_VIEW),
            self::read(new GetBookingsTool($hotel), Permission::BOOKINGS_VIEW),

            // Read: reports. Each report checks the permission its own screen
            // needs, so the guard leaves the check to the tool.
            ['tool' => new GetReportTool($hotel, $user), 'kind' => GuardedTool::READ, 'selfChecked' => true],

            // Read only, ever (D17): admin-only, outside the permission enum.
            ['tool' => new GetStaffTool($hotel), 'kind' => GuardedTool::READ, 'adminOnly' => true],
            ['tool' => new GetHotelSettingsTool($hotel), 'kind' => GuardedTool::READ, 'adminOnly' => true],

            // Write: guests and reservations.
            self::write(new CreateGuestTool($hotel), [Permission::GUESTS_CREATE], [Guest::class]),
            self::write(new UpdateGuestTool($hotel), [Permission::GUESTS_UPDATE], [Guest::class]),
            self::write(new CreateReservationTool($hotel), [Permission::RESERVATIONS_CREATE], [Reservation::class, ReservationRoom::class, Guest::class]),
            self::write(new UpdateReservationTool($hotel), [Permission::RESERVATIONS_UPDATE], [Reservation::class, ReservationRoom::class]),
            self::write(new CancelReservationTool($hotel), [Permission::RESERVATIONS_UPDATE], [Reservation::class, ReservationRoom::class]),
            self::write(new AssignRoomsTool($hotel), [Permission::RESERVATIONS_UPDATE], [ReservationRoom::class]),
            self::write(new CheckInTool($hotel, $user), [Permission::STAYS_CHECK_IN], [Stay::class, Reservation::class, Room::class]),
            self::write(new CheckOutTool($hotel, $user), [Permission::STAYS_CHECK_OUT], [Stay::class, Reservation::class, Room::class]),

            // Write: rooms, housekeeping, maintenance, tasks.
            self::write(new CreateRoomTool($hotel), [Permission::ROOMS_CREATE], [Room::class]),
            self::write(new SetHousekeepingStatusTool($hotel), [Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS], [Room::class]),
            self::write(new SetRoomOutOfOrderTool($hotel, $user), [Permission::ROOMS_SET_OUT_OF_ORDER], [Room::class, Task::class]),
            self::write(new UpdateOutOfOrderTool($hotel), [Permission::ROOMS_SET_OUT_OF_ORDER], [Room::class]),
            self::write(new ReturnRoomToServiceTool($hotel), [Permission::ROOMS_SET_OUT_OF_ORDER], [Room::class, Task::class]),
            self::write(new CreateTaskTool($hotel, $user), [Permission::TASKS_CREATE], [Task::class]),
            self::write(new UpdateTaskTool($hotel), [Permission::TASKS_UPDATE], [Task::class]),
            self::write(new ReportTaskIssueTool($hotel, $user), [Permission::TASKS_UPDATE], [Task::class, Room::class]),

            // Write: activities and bookings.
            self::write(new CreateActivityTool($hotel), [Permission::ACTIVITIES_CREATE], [Activity::class]),
            self::write(new CreateActivityBookingTool($hotel, $user), [Permission::BOOKINGS_CREATE], [Booking::class]),
            self::write(new UpdateBookingStatusTool($hotel), [Permission::BOOKINGS_UPDATE_STATUS], [Booking::class]),
            self::write(new DecideBookingCancellationTool($hotel), [Permission::BOOKINGS_UPDATE_STATUS], [Booking::class, Task::class]),

            // Write: the hotel's own knowledge base articles only (FR-022).
            self::write(new CreateKnowledgeArticleTool($hotel), [Permission::KNOWLEDGE_BASE_ARTICLES_CREATE], [KnowledgeBaseArticle::class]),
            self::write(new UpdateKnowledgeArticleTool($hotel), [Permission::KNOWLEDGE_BASE_ARTICLES_UPDATE], [KnowledgeBaseArticle::class]),
        ];
    }

    private static function read(Tool $tool, Permission ...$permissions): array
    {
        return ['tool' => $tool, 'kind' => GuardedTool::READ, 'permissions' => $permissions];
    }

    /**
     * @param  list<Permission>  $permissions
     * @param  list<class-string>  $writes
     */
    private static function write(Tool $tool, array $permissions, array $writes): array
    {
        return ['tool' => $tool, 'kind' => GuardedTool::WRITE, 'permissions' => $permissions, 'writes' => $writes];
    }
}

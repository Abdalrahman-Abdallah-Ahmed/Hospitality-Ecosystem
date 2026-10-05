<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\TaskNotificationReceipt;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Notifications\HousekeepingRoutingNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\WhatsAppReservationCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who is emailed when a task or reservation is created.
 *
 * Called from the code paths that create records on purpose (the task
 * endpoint, the AI tools, housekeeping and maintenance) rather than from a
 * model observer, so seeders and CSV imports never email real people.
 *
 * A task's notice goes to its assignee or, for a task assigned only to the
 * hotel's Housekeeping or Maintenance team, to that team's members. A receipt
 * per task and person makes it one notice per assignment, whatever is resaved
 * or retried (FR-026, FR-027).
 */
class CreationNotificationService
{
    /**
     * The assignee always hears about their task. The hotel's admins also
     * hear about it when an AI agent created it.
     */
    public function taskCreated(Task $task, bool $createdByAi): void
    {
        $this->notifyRecipients($task, $this->recipients($task));

        if ($createdByAi) {
            Notification::send($this->admins($task->hotel_id), new AiTaskCreatedNotification($task));
        }
    }

    /**
     * The task's assignee or team changed: whoever it moved away from can be
     * told again if it ever comes back to them, and whoever it moved to is
     * told now — unless they already were (a team member it was narrowed to).
     *
     * @param  array<string, mixed>  $before  the task's attributes before the change
     */
    public function taskReassigned(Task $task, array $before): void
    {
        if (($before['assigned_to_user_id'] ?? null) === $task->assigned_to_user_id
            && ($before['assigned_to_team_id'] ?? null) === $task->assigned_to_team_id) {
            return;
        }

        $recipients = $this->recipients($task);

        TaskNotificationReceipt::query()
            ->where('task_id', $task->id)
            ->whereNotIn('user_id', $recipients->pluck('id'))
            ->delete();

        $this->notifyRecipients($task, $recipients);
    }

    /**
     * An automatic task could not be routed to anyone. The hotel's admins
     * hear about it once per hotel, day and cause (FR-029).
     *
     * @param  'no_team'|'no_members'  $cause
     */
    public function routingProblem(Hotel $hotel, string $cause, Task $task): void
    {
        $now = CarbonImmutable::now($hotel->timezone);
        $key = "hk-routing:{$hotel->id}:{$now->toDateString()}:{$cause}";

        if (! Cache::add($key, true, $now->endOfDay())) {
            return;
        }

        Notification::send($this->admins($hotel->id), new HousekeepingRoutingNotification($hotel, $cause, $task));
    }

    /**
     * Whether a task's team (or lack of one) leaves nobody to tell, for the
     * routing alert. Null when someone will be told.
     *
     * @return 'no_team'|'no_members'|null
     */
    public function routingGap(Task $task): ?string
    {
        if ($task->assigned_to_user_id !== null) {
            return null;
        }

        if ($task->assigned_to_team_id === null) {
            return 'no_team';
        }

        return $this->recipients($task)->isEmpty() ? 'no_members' : null;
    }

    public function whatsAppReservationCreated(Reservation $reservation): void
    {
        Notification::send(
            $this->admins($reservation->hotel_id),
            new WhatsAppReservationCreatedNotification($reservation),
        );
    }

    /**
     * The assigned person; or, when only a team is assigned and it is the
     * hotel's active Housekeeping or Maintenance team, its members. Other
     * teams are not fanned out to (today's behavior).
     *
     * @return Collection<int, User>
     */
    private function recipients(Task $task): Collection
    {
        if ($task->assigned_to_user_id !== null) {
            return User::whereKey($task->assigned_to_user_id)->get();
        }

        if ($task->assigned_to_team_id === null) {
            return collect();
        }

        $hotel = Hotel::find($task->hotel_id);
        $fanOutTeams = array_filter([$hotel?->housekeeping_team_id, $hotel?->maintenance_team_id]);

        if (! in_array($task->assigned_to_team_id, $fanOutTeams, true)) {
            return collect();
        }

        $active = Team::withoutGlobalScope('hotel')
            ->whereKey($task->assigned_to_team_id)
            ->where('is_active', true)
            ->exists();

        return $active ? User::where('team_id', $task->assigned_to_team_id)->get() : collect();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function notifyRecipients(Task $task, Collection $users): void
    {
        foreach ($users as $user) {
            $inserted = TaskNotificationReceipt::query()->insertOrIgnore([
                'id' => (string) str()->uuid(),
                'task_id' => $task->id,
                'user_id' => $user->id,
                'notified_at' => now(),
            ]);

            if ($inserted === 1) {
                $user->notify(new TaskAssignedNotification($task));
            }
        }
    }

    /**
     * @return iterable<User>
     */
    private function admins(string $hotelId): iterable
    {
        return Hotel::find($hotelId)?->admins()->get() ?? [];
    }
}

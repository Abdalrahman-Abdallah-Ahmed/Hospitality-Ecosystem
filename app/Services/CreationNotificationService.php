<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\WhatsAppReservationCreatedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who is emailed when a task or reservation is created.
 *
 * Called from the code paths that create records on purpose (the task
 * endpoint and the AI tools) rather than from a model observer, so seeders
 * and CSV imports never email real people.
 */
class CreationNotificationService
{
    /**
     * The assignee always hears about their task. The hotel's admins also
     * hear about it when an AI agent created it.
     */
    public function taskCreated(Task $task, bool $createdByAi): void
    {
        if ($task->assigned_to_user_id !== null) {
            User::find($task->assigned_to_user_id)?->notify(new TaskAssignedNotification($task));
        }

        if ($createdByAi) {
            Notification::send($this->admins($task->hotel_id), new AiTaskCreatedNotification($task));
        }
    }

    public function whatsAppReservationCreated(Reservation $reservation): void
    {
        Notification::send(
            $this->admins($reservation->hotel_id),
            new WhatsAppReservationCreatedNotification($reservation),
        );
    }

    /**
     * @return iterable<User>
     */
    private function admins(string $hotelId): iterable
    {
        return Hotel::find($hotelId)?->admins()->get() ?? [];
    }
}

<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the staff member a new task is assigned to.
 */
class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * A task deleted before the queue gets to it has nothing left to announce.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Task $task)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return TaskMail::describe(
            (new MailMessage)
                ->subject("New task assigned to you: {$this->task->title}")
                ->greeting("Hello {$notifiable->name},")
                ->line('A new task has been assigned to you.'),
            $this->task,
        );
    }
}

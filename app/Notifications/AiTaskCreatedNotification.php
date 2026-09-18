<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a hotel's admins when one of the AI agents creates a task, so work
 * the AI raised on its own never sits unnoticed in the task list.
 */
class AiTaskCreatedNotification extends Notification implements ShouldQueue
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
                ->subject("AI created a task: {$this->task->title}")
                ->greeting("Hello {$notifiable->name},")
                ->line("The AI assistant created a new task at {$this->task->hotel->name}."),
            $this->task,
        );
    }
}

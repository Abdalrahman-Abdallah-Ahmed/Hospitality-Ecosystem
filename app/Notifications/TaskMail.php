<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The task details shared by every task email, so each notification only
 * writes its own subject and opening line.
 */
final class TaskMail
{
    public static function describe(MailMessage $mail, Task $task): MailMessage
    {
        $mail->line("**Task:** {$task->title}");

        if ($task->priority) {
            $mail->line('**Priority:** '.ucfirst($task->priority->value));
        }

        if ($task->due_date) {
            $mail->line('**Due:** '.$task->due_date->format('Y-m-d H:i'));
        }

        if ($task->room) {
            $mail->line("**Room:** {$task->room->room_number}");
        }

        if ($task->guest) {
            $mail->line('**Guest:** '.trim("{$task->guest->first_name} {$task->guest->last_name}"));
        }

        if ($task->description) {
            $mail->line($task->description);
        }

        return $mail;
    }
}

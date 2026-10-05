<?php

namespace App\Notifications;

use App\Models\Hotel;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a hotel's admins when an automatic housekeeping or maintenance task
 * has nobody to go to: no default team, or a team with no members (FR-029).
 * At most once per hotel, day and cause.
 */
class HousekeepingRoutingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  'no_team'|'no_members'  $cause
     */
    public function __construct(public Hotel $hotel, public string $cause, public Task $task)
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
        $problem = $this->cause === 'no_team'
            ? 'has no team to go to'
            : 'was assigned to a team with no members';

        return (new MailMessage)
            ->subject("Unassigned task at {$this->hotel->name}: {$this->task->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An automatic task \"{$this->task->title}\" {$problem}, so nobody was told about it.")
            ->line('Check the housekeeping and maintenance teams in the hotel settings, and add staff to those teams.');
    }
}

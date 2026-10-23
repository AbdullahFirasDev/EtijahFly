<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $organisation,
        public string $url,
        public Carbon $expiresAt,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.invitation.title', ['organisation' => $this->organisation]))
            ->line(__('notifications.invitation.body', ['organisation' => $this->organisation]))
            ->action(__('notifications.invitation.action'), $this->url)
            ->line(__('notifications.invitation.expires', ['date' => $this->expiresAt->toDateTimeString()]));
    }
}

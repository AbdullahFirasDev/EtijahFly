<?php

namespace App\Domain\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app (+ optional e-mail) notification. Title/body are translation keys rendered
 * in the recipient's locale, so the stored payload stays language-neutral.
 */
class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $params  translation parameters
     * @param  array<string, mixed>  $data  extra payload (ids for deep links)
     */
    public function __construct(
        public string $kind,
        public array $params = [],
        public array $data = [],
        public ?string $url = null,
        public bool $mail = false,
        public string $severity = 'info',
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->mail ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'severity' => $this->severity,
            'params' => $this->params,
            'data' => $this->data,
            'url' => $this->url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->locale ?? config('app.locale');

        $message = (new MailMessage)
            ->subject(__("notifications.{$this->kind}.title", $this->params, $locale))
            ->line(__("notifications.{$this->kind}.body", $this->params, $locale));

        if ($this->url) {
            $message->action(__('notifications.open', [], $locale), $this->url);
        }

        return $message;
    }
}

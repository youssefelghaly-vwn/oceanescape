<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));

        $minutes = Config::get('auth.passwords.' . Config::get('auth.defaults.passwords') . '.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Ocean Escape password')
            ->greeting('Hello')
            ->line('We received a request to reset the password for your Ocean Escape Cottages account.')
            ->action('Reset password', url($url))
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request this, no action is needed — your password stays as it is.')
            ->salutation('— Ocean Escape Cottages');
    }
}

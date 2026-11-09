<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordWithBabiLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('babi.frontend_url'), '/').'/?passwordReset='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)->subject('Đặt lại mật khẩu Babi')
            ->line('Nhận được yêu cầu đặt lại mật khẩu cho tài khoản Babi của bạn.')
            ->action('Đặt lại mật khẩu', $url)
            ->line('Liên kết có hiệu lực trong 60 phút. Nếu bạn không yêu cầu, hãy bỏ qua email này.');
    }
}

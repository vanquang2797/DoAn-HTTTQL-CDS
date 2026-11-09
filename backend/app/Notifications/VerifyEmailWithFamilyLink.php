<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;

class VerifyEmailWithFamilyLink extends VerifyEmail
{
    public function __construct(private ?string $familyJoinToken = null) {}

    protected function verificationUrl($notifiable)
    {
        $url = parent::verificationUrl($notifiable);

        return $this->familyJoinToken ? $url.'&familyJoin='.rawurlencode($this->familyJoinToken) : $url;
    }
}

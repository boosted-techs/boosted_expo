<?php

namespace App\Mail;

use App\Models\BusinessInvitation;
use Illuminate\Mail\Mailable;

class BusinessInvitationMail extends Mailable
{
    public function __construct(
        public BusinessInvitation $invitation,
        public string $plainToken,
    ) {}

    public function build(): static
    {
        return $this->subject('Invitation to Boosted Export ERP')
            ->view('emails.business-invitation')
            ->with(['acceptUrl' => route('invitations.show', $this->plainToken)]);
    }
}
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PlatformNoticeMail extends Mailable
{
    public function __construct(public string $titleText, public string $messageText) {}

    public function build(): static
    {
        return $this->subject($this->titleText)->view('emails.platform-notice');
    }
}

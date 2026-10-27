<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CustomerOrderingMail extends Mailable
{
    public function __construct(public string $businessName, public string $token) {}

    public function build(): static
    {
        return $this->subject('Your private ordering access — '.$this->businessName)->view('emails.customer-ordering');
    }
}

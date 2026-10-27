<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BoostedOnePackagesMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The package list is a public catalogue snapshot, not a contract or an
     * activation request. Prices are only included when configured as public.
     */
    public function __construct(
        public string $contactName,
        public string $businessName,
        public array $packages,
        public bool $isIndicative = false,
    ) {}

    public function build(): static
    {
        return $this->subject('Boosted One — ERP package information')
            ->view('emails.boosted-one-packages');
    }
}

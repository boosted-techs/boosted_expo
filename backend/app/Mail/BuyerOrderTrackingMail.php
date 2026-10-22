<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class BuyerOrderTrackingMail extends Mailable
{
    public function __construct(
        public string $orderReference,
        public string $plainToken,
        public string $expiresOn,
    ) {}

    public function build():static
    {
        return $this->subject('Export order tracking: '.$this->orderReference)
            ->text('emails.buyer-order-tracking')
            ->with(['trackingUrl'=>route('buyer-order.show',$this->plainToken)]);
    }
}

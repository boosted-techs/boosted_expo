<?php

namespace App\Mail;

use App\Models\Supplier;
use App\Models\SupplyRequest;
use Illuminate\Mail\Mailable;

class SupplierRequestMail extends Mailable
{
    public function __construct(
        public SupplyRequest $supplyRequest,
        public Supplier $supplier,
        public string $plainToken,
    ) {}

    public function build(): static
    {
        return $this->subject('Supply quotation request '.$this->supplyRequest->reference)
            ->view('emails.supply-request')
            ->with(['offerUrl'=>route('supplier-offer.show', $this->plainToken)]);
    }
}
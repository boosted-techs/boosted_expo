<?php
namespace App\Mail;

use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BoostedOneInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public BillingInvoice $invoice) {}
    public function build(): static
    {
        return $this->subject('Boosted One invoice '.$this->invoice->invoice_number)
            ->view('emails.boosted-one-invoice');
    }
}

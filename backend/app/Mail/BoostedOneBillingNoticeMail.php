<?php
namespace App\Mail;
use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class BoostedOneBillingNoticeMail extends Mailable {
 use Queueable,SerializesModels;
 public function __construct(public BillingInvoice $invoice,public string $messageText) {}
 public function build():static {
  return $this->subject('Boosted One billing reminder: '.$this->invoice->invoice_number)->view('emails.boosted-one-notice');
 }
}

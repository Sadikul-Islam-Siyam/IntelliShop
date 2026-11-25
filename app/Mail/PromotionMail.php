<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PromotionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public Product $product,
        public Discount $discount,
        public string $promoMessage
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Special Offer Just For You: ' . $this->product->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.promotion',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

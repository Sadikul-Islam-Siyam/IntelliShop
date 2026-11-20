<?php

namespace App\Jobs;

use App\Mail\PromotionMail;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductPromotion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class PromotionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public Product $product,
        public Discount $discount,
        public string $message
    ) {}

    public function handle(): ProductPromotion
    {
        // Deliver email to customer
        Mail::to($this->customer->email)->send(
            new PromotionMail($this->customer, $this->product, $this->discount, $this->message)
        );

        // Log sent promotion
        return ProductPromotion::create([
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'promotion_type' => 'email',
            'message' => $this->message,
            'sent_at' => now(),
            'status' => 'sent',
            'opened' => false,
        ]);
    }
}

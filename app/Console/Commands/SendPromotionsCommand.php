<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PromotionEngineService;
use Illuminate\Console\Command;

class SendPromotionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promotions:send {--customer= : Optional target customer ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate customer purchase histories and dispatch targeted promotion emails';

    /**
     * Execute the console command.
     */
    public function handle(PromotionEngineService $engine): int
    {
        $customerId = $this->option('customer');
        $customer = null;

        if ($customerId) {
            $customer = Customer::find($customerId);
            if (!$customer) {
                $this->error("Customer with ID {$customerId} not found.");
                return Command::FAILURE;
            }
        }

        $this->info('Starting promotion evaluation...');
        $result = $engine->run($customer);

        $this->info("Completed promotion run: {$result['dispatched_count']} queued, {$result['skipped_count']} skipped.");

        return Command::SUCCESS;
    }
}

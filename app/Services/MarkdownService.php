<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\Discount;
use App\Models\InventoryBatch;
use App\Models\MarkdownRecommendation;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class MarkdownService
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Run markdown evaluation for active perishable batches near expiry.
     *
     * @param int|null $branchId
     * @return Collection<MarkdownRecommendation>
     */
    public function runEvaluation(?int $branchId = null): Collection
    {
        $baselineDays = (int) ($this->settings->get('markdown_baseline_days', 30) ?? 30);
        $horizonDays = (int) ($this->settings->get('markdown_horizon_days', 14) ?? 14);
        $maxMarkdownPct = (float) ($this->settings->get('markdown_max_markdown_pct', 40.0) ?? 40.0);
        $stepPct = (float) ($this->settings->get('markdown_step_pct', 5.0) ?? 5.0);
        $approvalThresholdPct = (float) ($this->settings->get('markdown_approval_threshold_pct', 30.0) ?? 30.0);

        $query = InventoryBatch::query()
            ->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $batches = $query->with(['product', 'branch'])->get();
        $recommendations = collect();

        foreach ($batches as $batch) {
            $now = Carbon::today();
            $expiry = Carbon::parse($batch->expiry_date)->startOfDay();
            $daysRemaining = $now->diffInDays($expiry, false);

            // Rule: Expired batches are excluded from sale
            if ($daysRemaining <= 0) {
                continue;
            }

            // Calculate historical total sales in baseline window
            $cutoff = Carbon::now()->subDays($baselineDays);
            $totalSales = (float) SaleItem::query()
                ->where('product_id', $batch->product_id)
                ->whereHas('sale', function ($q) use ($batch, $cutoff) {
                    $q->where('status', 'completed')
                      ->where('branch_id', $batch->branch_id)
                      ->where('created_at', '>=', $cutoff);
                })
                ->sum('quantity');

            $avgDaily = $totalSales / max(1, $baselineDays);
            $projected = $avgDaily * $daysRemaining;
            $batchQty = (float) $batch->quantity_remaining;
            $surplus = max(0.0, $batchQty - $projected);

            // If surplus exists, calculate recommended markdown %
            if ($surplus > 0.0) {
                $surplusRatio = $surplus / $batchQty;
                $urgency = max(0.0, 1.0 - ((float) $daysRemaining / max(1.0, (float) $horizonDays)));
                $multiplier = min(1.0, $surplusRatio * (1.0 + $urgency));

                $rawPct = $maxMarkdownPct * $multiplier;
                $step = max(1.0, $stepPct);
                $roundedPct = round($rawPct / $step) * $step;
                $markdownPct = min($maxMarkdownPct, max(0.0, $roundedPct));

                $authority = $markdownPct > $approvalThresholdPct ? 'Super Admin' : 'Branch Manager';

                $rec = MarkdownRecommendation::updateOrCreate(
                    [
                        'batch_id' => $batch->id,
                        'status' => 'pending',
                    ],
                    [
                        'product_id' => $batch->product_id,
                        'branch_id' => $batch->branch_id,
                        'baseline_days' => $baselineDays,
                        'avg_daily_sales' => round($avgDaily, 2),
                        'days_remaining' => $daysRemaining,
                        'available_qty' => $batch->quantity_remaining,
                        'projected_sales' => round($projected, 2),
                        'surplus_qty' => round($surplus, 2),
                        'suggested_markdown_pct' => $markdownPct,
                        'max_markdown_pct' => $maxMarkdownPct,
                        'approval_authority' => $authority,
                        'generated_at' => now(),
                    ]
                );

                $recommendations->push($rec);
            }
        }

        return $recommendations;
    }

    /**
     * Approve markdown recommendation and instantiate batch-level discount.
     */
    public function approveRecommendation(MarkdownRecommendation $recommendation, User $user): Discount
    {
        $approvalThresholdPct = (float) ($this->settings->get('markdown_approval_threshold_pct', 30.0) ?? 30.0);

        // Authority enforcement: Markdowns above threshold require Super Admin / Admin
        if ($recommendation->suggested_markdown_pct > $approvalThresholdPct && !$user->isAdmin()) {
            throw new AuthorizationException(
                "Markdown of {$recommendation->suggested_markdown_pct}% exceeds the {$approvalThresholdPct}% threshold and requires Super Admin authorization."
            );
        }

        $batch = $recommendation->batch;

        // Create batch-scoped discount
        $discount = Discount::create([
            'name' => "Zero-Waste Markdown: {$batch->batch_no}",
            'type' => 'percentage',
            'value' => $recommendation->suggested_markdown_pct,
            'product_id' => $recommendation->product_id,
            'batch_id' => $recommendation->batch_id,
            'starts_at' => now(),
            'ends_at' => $batch->expiry_date,
            'status' => 'active',
        ]);

        $recommendation->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'discount_id' => $discount->id,
        ]);

        return $discount;
    }

    /**
     * Reject a markdown recommendation.
     */
    public function rejectRecommendation(MarkdownRecommendation $recommendation, User $user): void
    {
        $recommendation->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }
}

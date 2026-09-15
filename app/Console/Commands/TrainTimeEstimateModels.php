<?php

namespace App\Console\Commands;

use App\Services\ApprovalTimeMlService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan ml:train-time-estimator
 * Scheduled hourly in bootstrap/app.php.
 *
 * Fully automatic, by design — there is deliberately no admin "train now"
 * button for this model (see ApprovalTimeMlService). Every (category,
 * department) combo that currently has enough real decision history gets
 * (re)trained; trainFor() itself decides whether the result is worth
 * activating.
 */
class TrainTimeEstimateModels extends Command
{
    protected $signature = 'ml:train-time-estimator';
    protected $description = 'Trains (or retrains) the estimated-approval-time regression model for every category/department combo with enough real decision history.';

    public function handle(ApprovalTimeMlService $timeMl): int
    {
        $groups = $timeMl->trainableGroups();
        $activated = 0;

        foreach ($groups as $group) {
            $model = $timeMl->trainFor($group['ml_category'], $group['department']);
            if ($model) {
                $activated++;
            }
        }

        $this->info("Time-estimate training complete: {$groups->count()} eligible combo(s) checked, {$activated} model(s) (re)trained and activated.");

        return self::SUCCESS;
    }
}

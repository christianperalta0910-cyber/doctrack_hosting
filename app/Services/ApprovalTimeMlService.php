<?php

namespace App\Services;

use App\Models\DocumentAssignment;
use App\Models\MlTimeEstimateModel;
use Illuminate\Support\Collection;
use Phpml\Regression\LeastSquares;

/**
 * Trains one Linear Regression model per (category, department) combo,
 * predicting how long the NEXT decision in that combo will take. Two
 * features: the deciding approver's own historical average speed
 * (leave-one-out, so a row is never used to predict itself) and the day
 * of week — genuinely learnable signal, not just echoing the target back
 * at itself. Runs fully automatically (see TrainTimeEstimateModels) —
 * there is deliberately no manual "train now" control.
 *
 * See ApprovalForecastService's docblock for why THAT stays a plain
 * statistical average — this is the genuine ML regression counterpart,
 * gated behind having enough real history (MIN_TRAINING_SAMPLES) to mean
 * anything more than noise. Estimates for stages beyond the immediate
 * next one still fall back to that plain average, since we don't know in
 * advance which department will end up handling those.
 */
class ApprovalTimeMlService
{
    /** Below this many real decisions for a (category, department) combo, there isn't enough signal to train on. */
    public const MIN_TRAINING_SAMPLES = 20;

    /** Held back from training, purely to grade the result honestly. */
    private const TEST_FRACTION = 0.2;

    private ?Collection $rowsCache = null;

    public function __construct(private BusinessHoursService $businessHours)
    {
    }

    /** Every (category, department) combo with enough real decision history to be worth training on right now. */
    public function trainableGroups(): Collection
    {
        return $this->rows()
            ->groupBy(fn ($row) => $row->ml_category.'|'.$row->department)
            ->filter(fn (Collection $rows) => $rows->count() >= self::MIN_TRAINING_SAMPLES)
            ->map(fn (Collection $rows) => ['ml_category' => $rows->first()->ml_category, 'department' => $rows->first()->department])
            ->values();
    }

    /**
     * One row per (category, department) combo that has ANY real decision
     * history at all — read-only status for the ML Training admin page
     * (there is no "train now" control for this model; see this class's
     * docblock). Below the training floor it's just a progress count;
     * once trained, the active model's own stats.
     */
    public function statusForAllGroups(): Collection
    {
        return $this->rows()
            ->groupBy(fn ($row) => $row->ml_category.'|'.$row->department)
            ->map(function (Collection $rows) {
                $category = $rows->first()->ml_category;
                $department = $rows->first()->department;
                $model = MlTimeEstimateModel::activeFor($category, $department);

                return [
                    'ml_category' => $category,
                    'department' => $department,
                    'sample_count' => $rows->count(),
                    'model' => $model,
                ];
            })
            ->sortBy(fn ($row) => $row['ml_category'].$row['department'])
            ->values();
    }

    /**
     * Trains a fresh model for one (category, department) combo and, if
     * it scores better than whatever was previously active (or nothing
     * was), makes it the active one. Returns null if there still isn't
     * enough data, the new model didn't beat the existing one, or the
     * training data was too degenerate to fit (e.g. every row sharing
     * identical features) — training is a background, self-healing
     * process, so a bad batch just gets skipped rather than crashing the
     * scheduled command.
     */
    public function trainFor(string $category, string $department): ?MlTimeEstimateModel
    {
        $rows = $this->rows()
            ->where('ml_category', $category)
            ->where('department', $department)
            ->values();

        if ($rows->count() < self::MIN_TRAINING_SAMPLES) {
            return null;
        }

        $byApprover = $rows->groupBy('user_id');
        $historicalSpeedFor = function ($row) use ($byApprover, $rows) {
            $others = $byApprover[$row->user_id]->where('assignment_id', '!=', $row->assignment_id);

            return $others->isEmpty() ? $rows->avg('elapsed_seconds') : $others->avg('elapsed_seconds');
        };

        $samples = [];
        $targets = [];
        foreach ($rows as $row) {
            $samples[] = [$historicalSpeedFor($row), \Carbon\Carbon::parse($row->created_at)->dayOfWeek];
            $targets[] = (float) $row->elapsed_seconds;
        }

        // Shuffled before splitting — the rows are naturally in creation
        // order, and holding back only the LATEST slice (instead of a
        // random one) would test the model exclusively on however things
        // happened to be trending most recently, not a representative sample.
        $indices = range(0, count($samples) - 1);
        shuffle($indices);
        $testCount = max(1, (int) floor(count($indices) * self::TEST_FRACTION));
        $testIndices = array_slice($indices, 0, $testCount);
        $trainIndices = array_slice($indices, $testCount);

        try {
            $regression = new LeastSquares();
            $regression->train(
                array_map(fn ($i) => $samples[$i], $trainIndices),
                array_map(fn ($i) => $targets[$i], $trainIndices)
            );

            $predictions = $regression->predict(array_map(fn ($i) => $samples[$i], $testIndices));
            $predictions = is_array($predictions) ? $predictions : [$predictions];
            $testTargets = array_map(fn ($i) => $targets[$i], $testIndices);

            $absErrors = array_map(fn ($predicted, $actual) => abs($predicted - $actual), $predictions, $testTargets);
            $mae = (int) round(array_sum($absErrors) / count($absErrors));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $existing = MlTimeEstimateModel::activeFor($category, $department);
        if ($existing && $existing->mae_seconds <= $mae) {
            return null; // the existing model is at least as good — don't churn
        }

        MlTimeEstimateModel::where('ml_category', $category)->where('department', $department)
            ->update(['is_active' => false]);

        return MlTimeEstimateModel::create([
            'ml_category' => $category,
            'department' => $department,
            'version' => 'v'.now()->format('Ymd.His'),
            'intercept' => $regression->getIntercept(),
            'coefficients' => $regression->getCoefficients(),
            'mae_seconds' => $mae,
            'training_sample_count' => $rows->count(),
            'is_active' => true,
            'trained_at' => now(),
        ]);
    }

    /**
     * The predicted elapsed business-seconds for the very next decision,
     * using whichever eligible approver is currently slowest (same
     * "unanimous approval waits on the slowest" reasoning as
     * ApprovalForecastService's queue-depth padding) — or null if no
     * trained model exists yet for this (category, department) combo, or
     * none of the eligible approvers has decision history to feed it.
     */
    public function predictNextDecision(string $category, string $department, Collection $eligibleApprovers): ?int
    {
        $model = MlTimeEstimateModel::activeFor($category, $department);
        if (!$model) {
            return null;
        }

        $slowestSpeed = $eligibleApprovers
            ->map(fn ($approver) => $this->historicalSpeedFor($approver->user_id, $category, $department))
            ->filter()
            ->max();

        if ($slowestSpeed === null) {
            return null;
        }

        return max(0, (int) round($model->predict([$slowestSpeed, now()->dayOfWeek])));
    }

    private function historicalSpeedFor(int $userId, string $category, string $department): ?float
    {
        $rows = $this->rows()
            ->where('user_id', $userId)
            ->where('ml_category', $category)
            ->where('department', $department);

        return $rows->isEmpty() ? null : $rows->avg('elapsed_seconds');
    }

    /**
     * Every real (human, non-auto-approved) decision ever made by an
     * approver with a department set, with the business-hours-aware
     * elapsed time already computed per row — same raw material and same
     * exclusions as PerformanceInsightsService::decisions(), reused here
     * rather than duplicated. Department is required (not merely
     * preferred, as in ApprovalForecastService's fallback) because this
     * model is keyed on it.
     */
    private function rows(): Collection
    {
        if ($this->rowsCache !== null) {
            return $this->rowsCache;
        }

        return $this->rowsCache = DocumentAssignment::query()
            ->join('users', 'document_assignments.user_id', '=', 'users.user_id')
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->whereNotNull('document_assignments.acted_at')
            ->where('document_assignments.auto_approved', false)
            ->whereNotNull('users.department')
            ->get([
                'document_assignments.assignment_id',
                'document_assignments.user_id',
                'document_assignments.created_at',
                'document_assignments.acted_at',
                'users.department',
                'document_repository.ml_category',
            ])
            ->map(function ($row) {
                $row->elapsed_seconds = $this->businessHours->businessSecondsRemaining(
                    \Carbon\Carbon::parse($row->created_at),
                    \Carbon\Carbon::parse($row->acted_at)
                );

                return $row;
            });
    }
}

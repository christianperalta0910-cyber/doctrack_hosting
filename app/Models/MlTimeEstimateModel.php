<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlTimeEstimateModel extends Model
{
    protected $fillable = [
        'ml_category', 'department', 'version', 'intercept', 'coefficients',
        'mae_seconds', 'training_sample_count', 'is_active', 'trained_at',
    ];

    protected $casts = [
        'coefficients' => 'array',
        'is_active' => 'boolean',
        'trained_at' => 'datetime',
    ];

    public static function activeFor(string $category, string $department): ?self
    {
        return self::where('ml_category', $category)
            ->where('department', $department)
            ->where('is_active', true)
            ->first();
    }

    /** Linear Regression prediction: intercept + (coefficient . feature) for each feature, in order. */
    public function predict(array $features): float
    {
        $result = $this->intercept;
        foreach ($this->coefficients as $index => $coefficient) {
            $result += $coefficient * ($features[$index] ?? 0);
        }

        return $result;
    }
}

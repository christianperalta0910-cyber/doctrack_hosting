<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One trained Linear Regression model per (category, department) combo —
 * see ApprovalTimeMlService. Unlike ml_model_repository's SVM classifier,
 * a fitted Least Squares model is fully described by a small intercept +
 * coefficients array, so no on-disk model file/sidecar is needed here —
 * it's stored directly as columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_time_estimate_models', function (Blueprint $table) {
            $table->id();
            $table->string('ml_category');
            $table->string('department');
            $table->string('version');
            $table->double('intercept');
            $table->json('coefficients');
            // Mean Absolute Error, in seconds, measured against the held-out
            // test slice at training time — the "off by ~X" honesty check,
            // same spirit as ml_model_repository.accuracy_score.
            $table->unsignedInteger('mae_seconds');
            $table->unsignedInteger('training_sample_count');
            $table->boolean('is_active')->default(true);
            $table->timestamp('trained_at');
            $table->timestamps();

            $table->index(['ml_category', 'department', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_time_estimate_models');
    }
};

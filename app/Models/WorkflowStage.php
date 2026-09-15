<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStage extends Model
{
    protected $table = 'workflow_stages';
    protected $primaryKey = 'stage_id';

    protected $fillable = [
        'document_category', 'stage_name', 'sequence_order', 'description', 'is_archived', 'document_id',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function assignments()
    {
        return $this->hasMany(DocumentAssignment::class, 'stage_id', 'stage_id');
    }

    /**
     * Which department(s) own this stage — see WorkflowStageDepartment's
     * docblock. Zero rows means unrestricted by department (matches
     * approver_workflow_stages' "no rows = no restriction" convention).
     */
    public function departments()
    {
        return $this->hasMany(WorkflowStageDepartment::class, 'stage_id', 'stage_id');
    }

    public function departmentNames(): array
    {
        return $this->departments->pluck('department')->all();
    }

    /**
     * Deliberately unfiltered by is_archived — admin views (workflow
     * config, approver stage picker) need to see archived stages too.
     * Callers that route/assign live documents must chain an explicit
     * ->where('is_archived', false) themselves (see WorkflowService).
     */
    public function scopeForCategory($query, string $category)
    {
        return $query->where('document_category', $category)->orderBy('sequence_order');
    }

    /**
     * The real, admin-configured, category-wide pipeline only — excludes
     * one-off stages created for a single originator-directed document
     * (see WorkflowService::routeToCustomApprovers() and document_id's
     * migration docblock). Every admin-facing listing/management query
     * (Workflow Config, approver stage assignment, category routing
     * lookups) must go through this rather than querying the table
     * directly, so a document-scoped stage never appears there or gets
     * reused for some OTHER document's auto-routing.
     */
    public function scopeConfigured($query)
    {
        return $query->whereNull('document_id');
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }
}
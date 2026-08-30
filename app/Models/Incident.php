<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $fillable = ['incident_no', 'custody_transaction_id', 'borrower_user_id', 'reported_by_user_id', 'supporting_evidence_file_id', 'incident_type', 'reported_at', 'police_blotter_reference', 'appraisal_amount', 'rslddp_reference', 'status', 'remarks'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'appraisal_amount' => 'decimal:2'];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'supporting_evidence_file_id');
    }

    /**
     * SPMU Action Officer who inspected the property and reported this case.
     * Printed on the RSLDDP as the named reporting signatory.
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IncidentLine::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Sanction extends Model
{
    protected $fillable = [
        'borrower_violation_id',
        'borrower_user_id',
        'academic_period_id',
        'sanction_rule_id',
        'offense_no',
        'sanction_code',
        'sanction_label',
        'effective_from',
        'effective_to',
        'status',
        'confirmed_by_user_id',
        'signature_snapshot_id',
        'confirmed_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'offense_no' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(BorrowerViolation::class, 'borrower_violation_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SanctionRule::class, 'sanction_rule_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function signatureSnapshot(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'signature_snapshot_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(GeneratedDocument::class, 'subject');
    }
}

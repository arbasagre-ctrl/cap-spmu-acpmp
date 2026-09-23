<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BorrowerViolation extends Model
{
    protected $fillable = [
        'borrower_user_id',
        'custody_transaction_id',
        'academic_period_id',
        'violation_code',
        'violation_source',
        'details_json',
        'status',
        'detected_at',
        'detected_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function detectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function sanction(): HasOne
    {
        return $this->hasOne(Sanction::class);
    }
}

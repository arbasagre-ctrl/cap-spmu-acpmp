<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryJobLine extends Model
{
    protected $fillable = [
        'laundry_job_id',
        'custody_line_id',
        'issued_quantity',
        'received_quantity',
        'issue_type',
        'affected_quantity',
        'completed_quantity',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'issued_quantity' => 'integer',
            'received_quantity' => 'integer',
            'affected_quantity' => 'integer',
            'completed_quantity' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(LaundryJob::class, 'laundry_job_id');
    }

    public function custodyLine(): BelongsTo
    {
        return $this->belongsTo(CustodyLine::class);
    }
}

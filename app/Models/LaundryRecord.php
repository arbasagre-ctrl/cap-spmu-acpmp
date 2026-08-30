<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryRecord extends Model
{
    protected $fillable = [
        'return_line_id',
        'form_document_id',
        'accomplished_file_id',
        'uploaded_by_user_id',
        'uploaded_at',
        'verified_by_user_id',
        'worker_user_id',
        'worker_name',
        'worker_received_at',
        'worker_completed_at',
        'quantity_received',
        'received_condition',
        'cleaned_quantity',
        'damaged_quantity',
        'remarks',
        'status',
        'verified_at',
        'verification_remarks',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'worker_received_at' => 'datetime',
            'worker_completed_at' => 'datetime',
            'quantity_received' => 'integer',
            'cleaned_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(ReturnLine::class);
    }

    public function formDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'form_document_id');
    }

    public function accomplishedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'accomplished_file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}

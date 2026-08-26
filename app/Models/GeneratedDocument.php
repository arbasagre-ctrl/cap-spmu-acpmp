<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedDocument extends Model
{
    protected $fillable = ['template_id', 'stored_file_id', 'request_version_id', 'subject_type', 'subject_id', 'document_no', 'document_type', 'version_no', 'sha256', 'status', 'generated_at', 'invalidated_at', 'invalidation_reason'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'request_version_id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DownloadEvent::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceSubmission::class);
    }
}

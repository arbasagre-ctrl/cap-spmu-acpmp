<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SignatureSnapshot extends Model
{
    protected $fillable = ['user_signature_id', 'signer_user_id', 'snapshot_file_id', 'signer_name', 'signer_role', 'purpose_code', 'sha256', 'captured_at'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'snapshot_file_id');
    }

    /**
     * A SignatureSnapshot is the immutable, point-in-time record of what a
     * signer actually signed. Every document/Gate Pass/Borrower Slip that
     * embeds one relies on it never changing after capture - a later
     * profile-signature change (a new UserSignature) or correction must
     * never mutate or remove a snapshot already burned into a previously
     * generated document.
     */
    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            throw new RuntimeException('SignatureSnapshot records are immutable and cannot be updated after capture.');
        });

        static::deleting(function (self $snapshot): void {
            throw new RuntimeException('SignatureSnapshot records are immutable and cannot be deleted.');
        });
    }
}

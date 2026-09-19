<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BillingStatement extends Model
{
    protected $fillable = ['billing_no', 'borrower_user_id', 'responsible_spmu_user_id', 'issued_at', 'due_at', 'total_amount', 'status', 'source', 'remarks'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'due_at' => 'datetime', 'total_amount' => 'decimal:2'];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    /**
     * SPMU officer accountable for issuing this Billing Statement. Printed on
     * the statement as the named signatory for the handwritten signature.
     */
    public function responsibleSpmuUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_spmu_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(GeneratedDocument::class, 'subject');
    }

    /**
     * Whether this billing was assessed against a late-return (OverdueCase),
     * as opposed to a property accountability (Incident) case. Determined
     * from the line type set at issuance (billOverdue() vs billIncident() in
     * AccountabilityController), not from a separate stored flag, so it can
     * never drift out of sync with how the billing was actually created.
     */
    public function isLateReturnBilling(): bool
    {
        return $this->relationLoaded('lines')
            ? $this->lines->contains(fn (BillingLine $line): bool => $line->line_type === 'LATE_RETURN_FEE')
            : $this->lines()->where('line_type', 'LATE_RETURN_FEE')->exists();
    }

    /**
     * Canonical user-facing name for this billing, so every view/document
     * that names it agrees with the others instead of each re-deciding
     * "Billing Statement" vs "Late Return Billing Statement" on its own.
     */
    public function displayLabel(): string
    {
        return $this->isLateReturnBilling() ? 'Late Return Billing Statement' : 'Billing Statement';
    }
}

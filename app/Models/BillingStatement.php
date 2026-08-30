<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BillingStatement extends Model
{
    protected $fillable = ['billing_no', 'borrower_user_id', 'responsible_spmu_user_id', 'issued_at', 'due_at', 'total_amount', 'status', 'remarks'];

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
}

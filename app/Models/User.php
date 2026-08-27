<?php

namespace App\Models;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'organizational_unit_id',
        'employee_no',
        'full_name', 'designation',
        'employment_type',
        'email',
        'mobile_no',
        'notification_preferences',
        'account_status',
        'access_classification',
        'password', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'account_status' => AccountStatus::class,
            'access_classification' => AccessClassification::class,
            'notification_preferences' => 'array',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_by_user_id', 'assigned_at', 'revoked_at']);
    }

    public function hasRole(UserRole|string $role): bool
    {
        $code = $role instanceof UserRole ? $role->value : strtoupper($role);

        return $this->roles()
            ->where('role_code', $code)
            ->wherePivotNull('revoked_at')
            ->exists();
    }

    /** @return list<string> */
    public function allowedWorkspaces(): array
    {
        $workspace = $this->primaryWorkspace();

        return $workspace ? [$workspace] : [];
    }

    public function primaryWorkspace(): ?string
    {
        $classification = $this->resolvedAccessClassification();

        if (! $classification?->isPortalEnabled()) {
            return null;
        }

        return $classification->primaryWorkspace()?->value;
    }

    public function mayBorrow(): bool
    {
        return $this->resolvedAccessClassification()?->mayBorrow() ?? false;
    }

    public function hasWorkspace(string $workspace): bool
    {
        return in_array(strtoupper($workspace), $this->allowedWorkspaces(), true);
    }

    public function activeDelegationFor(string $officeRole): ?TemporaryDelegation
    {
        return TemporaryDelegation::query()
            ->where('delegate_user_id', $this->id)
            ->where('office_role', strtoupper($officeRole))
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('effective_from', '<=', now())
            ->where('effective_to', '>=', now())
            ->latest('effective_from')
            ->first();
    }

    /** @return list<string> */
    public function delegatedApprovalWorkspaces(): array
    {
        return TemporaryDelegation::query()
            ->where('delegate_user_id', $this->id)
            /*
             * The active workflow permits temporary delegation only for SPMU
             * approval/signatory authority. GSU/VPAF approval stages are retired.
             */
            ->where('office_role', UserRole::Spmu->value)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('effective_from', '<=', now())
            ->where('effective_to', '>=', now())
            ->distinct()
            ->pluck('office_role')
            ->map(fn (string $workspace) => strtoupper($workspace))
            ->values()
            ->all();
    }

    public function currentSignature(): HasOne
    {
        return $this->hasOne(UserSignature::class)->where('status', 'ACTIVE')->latestOfMany();
    }

    public function borrowingRequests(): HasMany
    {
        return $this->hasMany(BorrowingRequest::class, 'borrower_user_id');
    }

    public function activeRestrictions(): HasMany
    {
        return $this->hasMany(BorrowerRestriction::class, 'borrower_user_id')
            ->where('status', 'ACTIVE')
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
            });
    }

    private function resolvedAccessClassification(): ?AccessClassification
    {
        return AccessClassification::tryFrom(strtoupper((string) $this->getRawOriginal('access_classification')));
    }
}

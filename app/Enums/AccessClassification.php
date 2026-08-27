<?php

namespace App\Enums;

enum AccessClassification: string
{
    case BorrowerOnly = 'BORROWER_ONLY';
    case SpmuHead = 'SPMU_HEAD';
    case SpmuOfficer = 'SPMU_OFFICER';
    case IctuMaintainer = 'ICTU_MAINTAINER';

    /*
     * Legacy values are retained only so old database/history records can
     * still be read safely. They are not assignable and cannot open a portal.
     */
    case RetiredInactive = 'RETIRED_INACTIVE';
    case GsuHead = 'GSU_HEAD';
    case VpafHead = 'VPAF_HEAD';

    public function label(): string
    {
        return match ($this) {
            self::BorrowerOnly => 'Borrower',
            self::SpmuHead => 'SPMU Admin / Head',
            self::SpmuOfficer => 'SPMU Action Officer',
            self::IctuMaintainer => 'ICTU Maintainer',
            self::RetiredInactive => 'Retired / Inactive Account',
            self::GsuHead => 'Retired Signatory-Only Record',
            self::VpafHead => 'Retired Signatory-Only Record',
        };
    }

    /**
     * User-facing choices for new/edited accounts.
     * Borrower is a requester user type; active staff roles are SPMU
     * Admin/Head, SPMU Action Officer, and ICTU Maintainer.
     *
     * @return list<self>
     */
    public static function assignableCases(): array
    {
        return [
            self::BorrowerOnly,
            self::SpmuHead,
            self::SpmuOfficer,
            self::IctuMaintainer,
        ];
    }

    public function isPortalEnabled(): bool
    {
        return in_array($this, self::assignableCases(), true);
    }

    /** @return list<UserRole> */
    public function roles(): array
    {
        return match ($this) {
            self::BorrowerOnly => [UserRole::Borrower],
            self::SpmuHead, self::SpmuOfficer => [UserRole::Spmu],
            self::IctuMaintainer => [UserRole::Ictu],
            self::RetiredInactive => [],
            self::GsuHead => [UserRole::Gsu],
            self::VpafHead => [UserRole::Vpaf],
        };
    }

    public function primaryWorkspace(): ?UserRole
    {
        return $this->roles()[0] ?? null;
    }

    /** @return list<string> */
    public function workspaces(): array
    {
        if (! $this->isPortalEnabled()) {
            return [];
        }

        return array_map(fn (UserRole $role) => $role->value, $this->roles());
    }

    public function mayBorrow(): bool
    {
        return $this === self::BorrowerOnly;
    }
}

<?php

namespace App\Enums;

enum UserRole: string
{
    case Borrower = 'BORROWER';
    case Spmu = 'SPMU';
    /*
     * Legacy database values only. GSU and VPAF are physical signatories
     * on the request letter, not active application roles.
     */
    case Gsu = 'GSU';
    case Vpaf = 'VPAF';
    case Ictu = 'ICTU';

    public function label(): string
    {
        return match ($this) {
            self::Borrower => 'Borrower',
            self::Spmu => 'Supply and Property Management Unit',
            self::Gsu => 'Retired Signatory-Only Role',
            self::Vpaf => 'Retired Signatory-Only Role',
            self::Ictu => 'Information and Communications Technology Unit',
        };
    }
}

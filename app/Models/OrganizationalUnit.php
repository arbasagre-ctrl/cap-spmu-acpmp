<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationalUnit extends Model
{
    use HasFactory;

    public const DIVISION_ADMINISTRATION = 'ADMINISTRATION';
    public const DIVISION_ACADEMIC = 'ACADEMIC';
    public const DIVISION_RIC = 'RESEARCH_INNOVATION_COLLABORATION';

    protected $fillable = ['parent_unit_id', 'unit_code', 'unit_name', 'unit_type', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function divisionCode(): ?string
    {
        return match (strtoupper((string) $this->unit_type)) {
            'ACADEMIC_UNIT' => self::DIVISION_ACADEMIC,
            'RESEARCH_UNIT', 'RIC_UNIT' => self::DIVISION_RIC,
            'ADMINISTRATIVE_UNIT', 'OPERATIONAL_UNIT' => self::DIVISION_ADMINISTRATION,
            default => null,
        };
    }

    public function divisionLabel(): ?string
    {
        return match ($this->divisionCode()) {
            self::DIVISION_ADMINISTRATION => 'Administrative',
            self::DIVISION_ACADEMIC => 'Academic',
            self::DIVISION_RIC => 'Research, Innovation and Collaboration',
            default => null,
        };
    }

    public static function unitTypeForDivision(string $divisionCode): string
    {
        return match (strtoupper($divisionCode)) {
            self::DIVISION_ACADEMIC => 'ACADEMIC_UNIT',
            self::DIVISION_RIC => 'RESEARCH_UNIT',
            default => 'ADMINISTRATIVE_UNIT',
        };
    }
}

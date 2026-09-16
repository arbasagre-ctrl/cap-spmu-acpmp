<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class OrganizationalUnit extends Model
{
    use HasFactory;

    public const DIVISION_ADMINISTRATION = 'ADMINISTRATION';
    public const DIVISION_ACADEMIC = 'ACADEMIC';
    public const DIVISION_RIC = 'RESEARCH_INNOVATION_COLLABORATION';

    public const TYPE_CLASSIFICATION = 'ORGANIZATIONAL_CLASSIFICATION';
    public const TYPE_BRANCH = 'ORGANIZATIONAL_BRANCH';

    /** @var list<string> */
    private const SELECTABLE_UNIT_TYPES = [
        'ADMINISTRATIVE_UNIT',
        'ACADEMIC_UNIT',
        'RESEARCH_UNIT',
        'RIC_UNIT',
        'OPERATIONAL_UNIT',
    ];

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

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    /**
     * Active Office / College / Unit records that ICTU may assign.
     * Classification and branch nodes stay active for hierarchy context but
     * are intentionally never normal form selections.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActiveSelectable(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereIn('unit_type', self::SELECTABLE_UNIT_TYPES);
    }

    public function isSelectable(): bool
    {
        return $this->active
            && in_array(strtoupper((string) $this->unit_type), self::SELECTABLE_UNIT_TYPES, true);
    }

    public function divisionCode(): ?string
    {
        $unit = $this;
        $visited = [];

        /*
         * Leaves are normally typed by their classification, but the final
         * CSPC structure also has active branch nodes.  Walk ancestors so a
         * valid leaf below a branch always resolves to the one persisted
         * classification code.  The visited guard keeps corrupted cyclic
         * legacy data from causing an unbounded query loop.
         */
        while ($unit) {
            if ($unit->id !== null && isset($visited[$unit->id])) {
                return null;
            }

            if ($unit->id !== null) {
                $visited[$unit->id] = true;
            }

            $directCode = $unit->directDivisionCode();

            if ($directCode !== null) {
                return $directCode;
            }

            $unit = $unit->relationLoaded('parent')
                ? $unit->parent
                : $unit->parent()->first();
        }

        return null;
    }

    public function divisionLabel(): ?string
    {
        return self::classificationLabels()[$this->divisionCode()] ?? null;
    }

    /** @return array<string, string> */
    public static function classificationLabels(): array
    {
        return [
            self::DIVISION_ADMINISTRATION => 'Administrative',
            self::DIVISION_ACADEMIC => 'Academic',
            self::DIVISION_RIC => 'Research, Innovation, & Collaboration',
        ];
    }

    /** @return list<string> */
    public static function selectableUnitTypes(): array
    {
        return self::SELECTABLE_UNIT_TYPES;
    }

    public static function unitTypeForDivision(string $divisionCode): string
    {
        return match (strtoupper($divisionCode)) {
            self::DIVISION_ACADEMIC => 'ACADEMIC_UNIT',
            self::DIVISION_RIC => 'RESEARCH_UNIT',
            default => 'ADMINISTRATIVE_UNIT',
        };
    }

    private function directDivisionCode(): ?string
    {
        $unitType = strtoupper((string) $this->unit_type);

        if ($unitType === self::TYPE_CLASSIFICATION
            && array_key_exists($this->unit_code, self::classificationLabels())) {
            return $this->unit_code;
        }

        return match ($unitType) {
            'ACADEMIC_UNIT' => self::DIVISION_ACADEMIC,
            'RESEARCH_UNIT', 'RIC_UNIT' => self::DIVISION_RIC,
            'ADMINISTRATIVE_UNIT', 'OPERATIONAL_UNIT' => self::DIVISION_ADMINISTRATION,
            default => null,
        };
    }
}

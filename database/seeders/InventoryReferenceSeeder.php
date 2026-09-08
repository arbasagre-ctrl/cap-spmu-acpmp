<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class InventoryReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            'EQUIPMENT' => 'Equipment',
            'FURNITURE' => 'Furniture',
            'LINEN' => 'Linen',
            'SAFETY_TRAFFIC' => 'Safety and Traffic Control',
        ])->mapWithKeys(fn (string $name, string $code) => [$code => InventoryCategory::query()->firstOrCreate(['category_code' => $code], ['category_name' => $name, 'active' => true])]);

        $units = collect([
            'UNIT' => 'Unit',
            'PIECE' => 'Piece',
            'LOT' => 'Lot',
        ])->mapWithKeys(fn (string $name, string $code) => [$code => UnitOfMeasure::query()->firstOrCreate(['unit_code' => $code], ['unit_name' => $name, 'decimal_scale' => 0, 'active' => true])]);

        foreach ($this->items() as [$category, $description, $unit, $quantity, $laundry, $offCampus, $provisional]) {
            InventoryItem::query()->firstOrCreate(
                ['category_id' => $categories[$category]->id, 'unique_description' => $description],
                [
                    'unit_id' => $units[$unit]->id,
                    'specification' => $provisional ? 'Provisional opening value pending SPMU physical verification.' : 'Opening inventory from the approved SPMU borrowing inventory baseline.',
                    'total_quantity' => $quantity,
                    'condition_code' => 'SERVICEABLE',
                    'borrowable' => true,
                    'off_campus_allowed' => $offCampus,
                    'laundry_required' => $laundry,
                    'provisional' => $provisional,
                    'active' => true,
                ],
            );
        }
    }

    /** @return list<array{string, string, string, int, bool, bool, bool}> */
    private function items(): array
    {
        return [
            ['EQUIPMENT', 'Multimedia Projector with screen and stand', 'UNIT', 2, false, false, false],
            ['EQUIPMENT', 'Podium - Wooden', 'UNIT', 3, false, false, false],
            ['EQUIPMENT', 'Podium - Stainless Glass', 'UNIT', 7, false, false, false],
            ['EQUIPMENT', 'Sound System', 'UNIT', 2, false, false, false],
            ['EQUIPMENT', 'LED Wall', 'LOT', 1, false, false, false],
            ['EQUIPMENT', 'Microphones', 'UNIT', 12, false, false, false],
            ['FURNITURE', 'Round Table', 'PIECE', 48, false, false, false],
            ['FURNITURE', 'Rectangular Table', 'PIECE', 35, false, false, false],
            ['LINEN', 'Round Table Cloth - White', 'PIECE', 50, true, false, false],
            ['LINEN', 'Round Table Cloth - Cream', 'PIECE', 50, true, false, false],
            ['LINEN', 'Round Table Cloth - Blue', 'PIECE', 50, true, false, false],
            ['LINEN', 'Round Table Cloth - Old Rose', 'PIECE', 50, true, false, false],
            ['LINEN', 'Round Table Cloth - Yellow', 'PIECE', 50, true, false, false],
            ['LINEN', 'Rectangular Table Cloth - Yellow', 'PIECE', 24, true, false, false],
            ['LINEN', 'Rectangular Table Cloth - Unspecified', 'PIECE', 24, true, false, false],
            ['LINEN', 'Table Top Cloth - Peach', 'PIECE', 40, true, false, false],
            ['LINEN', 'Table Top Cloth - Light Green', 'PIECE', 40, true, false, false],
            ['LINEN', 'Table Top Cloth - Brown', 'PIECE', 40, true, false, false],
            ['LINEN', 'Table Top Cloth - Gray', 'PIECE', 40, true, false, false],
            ['LINEN', 'Seat Cover - Yellow', 'PIECE', 300, true, false, false],
            ['LINEN', 'Seat Cover - Blue', 'PIECE', 300, true, false, false],
            ['LINEN', 'Seat Cover - White', 'PIECE', 300, true, false, false],
            ['LINEN', 'Seat Cover - Cream', 'PIECE', 300, true, false, false],
            ['LINEN', 'Seat Cover - Old Rose', 'PIECE', 300, true, false, false],
            ['FURNITURE', 'Monoblock Chairs', 'PIECE', 1020, false, false, false],
            ['SAFETY_TRAFFIC', 'Barricade', 'PIECE', 6, false, true, true],
        ];
    }
}

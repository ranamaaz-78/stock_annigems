<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * TaxRateSeeder
 *
 * The starting lists for a new install: Spanish IVA and Recargo de
 * equivalencia. Runs on every deploy but only fills an empty table, so
 * SuperAdmin's later edits and deletions are never put back.
 */
class TaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (TaxRate::withTrashed()->exists()) {
            return;
        }

        $rows = [
            [TaxRate::KIND_IVA, 'IVA 21%', 21, false, true],
            [TaxRate::KIND_IVA, 'IVA 10%', 10, false, false],
            [TaxRate::KIND_IVA, 'IVA 4%', 4, false, false],
            [TaxRate::KIND_IVA, 'Exempt', 0, true, false],
            [TaxRate::KIND_RECARGO, 'Recargo 5.2%', 5.2, false, false],
            [TaxRate::KIND_RECARGO, 'Recargo 1.4%', 1.4, false, false],
            [TaxRate::KIND_RECARGO, 'Recargo 0.5%', 0.5, false, false],
            [TaxRate::KIND_RECARGO, 'Recargo 0.75%', 0.75, false, false],
        ];

        foreach ($rows as $order => [$kind, $name, $rate, $exempt, $default]) {
            TaxRate::query()->create([
                'kind' => $kind,
                'name' => $name,
                'rate' => $rate,
                'is_exempt' => $exempt,
                'is_default' => $default,
                'is_active' => true,
                'sort_order' => $order,
            ]);
        }
    }
}

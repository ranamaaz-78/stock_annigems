<?php

namespace Database\Seeders;

use App\Models\MetalType;
use App\Models\Purity;
use Illuminate\Database\Seeder;

/**
 * MetalPuritySeeder
 *
 * Idempotent default metals and fineness values. Purity.value is stored for
 * the future Quotation module and is not used in jewellery CRUD.
 */
class MetalPuritySeeder extends Seeder
{
    /**
     * Seed metals and bound purities without duplicating on re-run.
     */
    public function run(): void
    {
        $gold = $this->metal('Gold', 1);
        $this->metal('White Gold', 2);
        $this->metal('Rose Gold', 3);
        $silver = $this->metal('Silver', 4);
        $platinum = $this->metal('Platinum', 5);

        $this->purities($gold, [
            ['24K', '1.0000', 1],
            ['22K', '0.9166', 2],
            ['21K', '0.8750', 3],
            ['18K', '0.7500', 4],
            ['14K', '0.5850', 5],
        ]);

        $this->purities($this->metal('White Gold', 2), [
            ['18K', '0.7500', 1],
            ['14K', '0.5850', 2],
        ]);

        $this->purities($this->metal('Rose Gold', 3), [
            ['18K', '0.7500', 1],
            ['14K', '0.5850', 2],
        ]);

        $this->purities($silver, [
            ['925', '0.9250', 1],
        ]);

        $this->purities($platinum, [
            ['950', '0.9500', 1],
            ['900', '0.9000', 2],
        ]);
    }

    /**
     * @return MetalType
     */
    private function metal(string $name, int $sort): MetalType
    {
        return MetalType::query()->withTrashed()->updateOrCreate(
            ['name' => $name],
            [
                'is_active' => true,
                'sort_order' => $sort,
                'deleted_at' => null,
            ]
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $rows
     */
    private function purities(MetalType $metal, array $rows): void
    {
        foreach ($rows as [$name, $value, $sort]) {
            Purity::query()->withTrashed()->updateOrCreate(
                [
                    'metal_type_id' => $metal->id,
                    'name' => $name,
                ],
                [
                    'value' => $value,
                    'is_active' => true,
                    'sort_order' => $sort,
                    'deleted_at' => null,
                ]
            );
        }
    }
}

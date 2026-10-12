<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Settings\SettingCaster;
use App\Support\Settings\SettingCatalog;
use Illuminate\Database\Seeder;

/**
 * SettingSeeder
 *
 * Inserts every catalog key with its documented default. Idempotent: a
 * key that already exists is left alone, so re-running a deploy cannot
 * silently reset a shop's tax rate or numbering prefix.
 *
 * Branch settings are not seeded globally — each branch receives catalog
 * defaults on first read, and writes only when an Admin saves.
 */
class SettingSeeder extends Seeder
{
    /**
     * Insert missing global keys only.
     */
    public function run(): void
    {
        foreach (SettingCatalog::definitions() as $definition) {
            $exists = Setting::query()->where('key', $definition['key'])->exists();

            if ($exists) {
                continue;
            }

            Setting::query()->create([
                'key' => $definition['key'],
                'value' => SettingCaster::encode($definition['default'], $definition['type']),
                'type' => $definition['type'],
                'group' => $definition['group'],
                'description' => $definition['description'],
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Enums\SettingGroup;
use App\Enums\SettingScope;
use App\Models\BranchSetting;
use App\Models\SettingHistory;
use App\Models\User;
use App\Support\Settings\BranchSettingCatalog;
use App\Support\Settings\SettingCaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BranchSettingService
 *
 * The only reader and writer of `branch_settings`. Contact details are not
 * stored here — they edit the Branch record through BranchService so the
 * name printed on an invoice has one source of truth.
 *
 * Cache key includes branch_id. forget() runs in the same request as the
 * write so a till does not keep printing the previous label size.
 */
class BranchSettingService
{
    public const CACHE_PREFIX = 'settings.branch.';

    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    /**
     * Typed value for one branch catalog key.
     */
    public function get(int $branchId, string $key): mixed
    {
        $definition = BranchSettingCatalog::find($key);

        if ($definition === null) {
            return null;
        }

        $bag = $this->all($branchId);

        if (! array_key_exists($key, $bag)) {
            return $definition['default'];
        }

        return SettingCaster::decode($bag[$key], $definition['type']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getGroup(int $branchId, SettingGroup $group): array
    {
        $values = [];

        foreach (BranchSettingCatalog::forGroup($group) as $definition) {
            $values[$definition['key']] = $this->get($branchId, $definition['key']);
        }

        return $values;
    }

    /**
     * @return array<string, ?string>
     */
    public function all(int $branchId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('branch_settings')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_PREFIX.$branchId, function () use ($branchId): array {
            return BranchSetting::query()
                ->where('branch_id', $branchId)
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * Persist one group's values for one branch.
     *
     * @param  array<string, mixed>  $values
     */
    public function setGroup(int $branchId, SettingGroup $group, array $values, User $actor): void
    {
        if ($actor->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch' => 'You can only change settings for your own branch.',
            ]);
        }

        $definitions = BranchSettingCatalog::forGroup($group);

        if ($definitions === []) {
            throw ValidationException::withMessages([
                'group' => 'That settings group does not exist.',
            ]);
        }

        $allowed = array_column($definitions, 'key');
        $unknown = array_diff(array_keys($values), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => 'Unknown setting: '.implode(', ', $unknown).'. Keys cannot be created from a form.',
            ]);
        }

        DB::transaction(function () use ($definitions, $values, $actor, $branchId): void {
            foreach ($definitions as $definition) {
                $key = $definition['key'];

                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $encoded = SettingCaster::encode($values[$key], $definition['type']);
                $row = BranchSetting::query()
                    ->where('branch_id', $branchId)
                    ->where('key', $key)
                    ->first();
                $old = $row?->value;

                if ($row === null) {
                    $row = new BranchSetting([
                        'branch_id' => $branchId,
                        'key' => $key,
                        'type' => $definition['type'],
                        'group' => $definition['group'],
                    ]);
                }

                if ((string) $old === (string) $encoded) {
                    continue;
                }

                $row->value = $encoded;
                $row->type = $definition['type'];
                $row->group = $definition['group'];
                $row->save();

                $history = new SettingHistory([
                    'scope' => SettingScope::BRANCH,
                    'branch_id' => $branchId,
                    'key' => $key,
                    'old_value' => $old,
                    'new_value' => $encoded,
                ]);
                $history->save();

                $this->auditLog->log('branch_setting.updated', $row, ['value' => $old], ['value' => $encoded], $actor);
            }
        });

        $this->forget($branchId);
    }

    /**
     * Drop the cached bag for one branch.
     */
    public function forget(int $branchId): void
    {
        Cache::forget(self::CACHE_PREFIX.$branchId);
    }
}

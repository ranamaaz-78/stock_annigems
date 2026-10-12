<?php

namespace App\Services;

use App\Enums\SettingGroup;
use App\Enums\SettingScope;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\User;
use App\Support\Settings\SettingCaster;
use App\Support\Settings\SettingCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SettingService
 *
 * The only reader and writer of the global `settings` table.
 *
 * Controllers, Blade, jobs and other services call get() / the setting()
 * helper. They never query Setting::query() themselves. That keeps the
 * typed-cast and the catalog fallback in one place, so a missing key on a
 * fresh install cannot take a page down.
 *
 * Caching: the whole bag is stored under settings.global for
 * config('annigems.reports.dashboard_cache_seconds') is the wrong TTL here —
 * a stale tax rate is a financial problem, not a slightly-old dashboard.
 * The bag is cached forever and forgotten on every write. forget() runs
 * inside the same request as the write, before the response is sent, so no
 * branch continues operating on the previous tax rate.
 *
 * There is no branch_id on this table and this service never accepts one.
 * Adding one would break the governing rule: business settings are global.
 */
class SettingService
{
    public const CACHE_KEY = 'settings.global';

    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    /**
     * Typed value for one catalog key, falling back to the code default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $definition = SettingCatalog::find($key);

        if ($definition === null) {
            return $default;
        }

        $bag = $this->all();

        if (! array_key_exists($key, $bag)) {
            return $definition['default'] ?? $default;
        }

        return SettingCaster::decode($bag[$key], $definition['type']);
    }

    /**
     * Typed values for every key in a group.
     *
     * @return array<string, mixed>
     */
    public function getGroup(SettingGroup $group): array
    {
        $values = [];

        foreach (SettingCatalog::forGroup($group) as $definition) {
            $values[$definition['key']] = $this->get($definition['key']);
        }

        return $values;
    }

    /**
     * Raw stored strings keyed by catalog key (missing keys omitted).
     *
     * @return array<string, ?string>
     */
    public function all(): array
    {
        // Never cache an empty bag from a pre-migrate boot — the next
        // request after SettingSeeder would keep serving catalog defaults.
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return Setting::query()->pluck('value', 'key')->all();
        });
    }

    /**
     * Persist one group's values. Unknown keys are refused.
     *
     * Numbering starts at or below the highest issued number are raised
     * automatically so a low customer start cannot block jewellery
     * prefixes in the same save.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, int> Start keys that were raised, keyed to the new floor.
     */
    public function setGroup(SettingGroup $group, array $values, User $actor): array
    {
        $definitions = SettingCatalog::forGroup($group);
        $raised = [];

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

        if ($group === SettingGroup::NUMBERING) {
            $submitted = $values;
            $values = $this->normalizeNumberingStarts($values);

            foreach (SettingCatalog::numberingStartMap() as $settingKey => $sequenceKey) {
                if (! array_key_exists($settingKey, $submitted)) {
                    continue;
                }

                if ((int) $values[$settingKey] > (int) $submitted[$settingKey]) {
                    $raised[$settingKey] = (int) $values[$settingKey];
                }
            }
        }

        DB::transaction(function () use ($definitions, $values, $actor, $group): void {
            foreach ($definitions as $definition) {
                $key = $definition['key'];

                if (! array_key_exists($key, $values)) {
                    continue;
                }

                // File fields may send an empty string when the operator did
                // not replace the current logo; keep the stored path.
                if (in_array($definition['widget'], ['file'], true) && ($values[$key] === null || $values[$key] === '')) {
                    continue;
                }

                $raw = $values[$key];
                if ($definition['group'] === SettingGroup::NUMBERING && str_ends_with($key, '_prefix')) {
                    $raw = strtoupper(trim((string) $raw));
                }

                $encoded = SettingCaster::encode($raw, $definition['type']);
                $row = Setting::query()->where('key', $key)->first();
                $old = $row?->value;

                if ($row === null) {
                    $row = new Setting([
                        'key' => $key,
                        'type' => $definition['type'],
                        'group' => $definition['group'],
                        'description' => $definition['description'],
                    ]);
                }

                if ((string) $old === (string) $encoded) {
                    continue;
                }

                $row->value = $encoded;
                $row->type = $definition['type'];
                $row->group = $definition['group'];
                $row->description = $definition['description'];
                $row->save();

                $this->recordHistory($key, $old, $encoded, $actor);
                $this->auditLog->log('setting.updated', $row, ['value' => $old], ['value' => $encoded], $actor);
            }

            if ($group === SettingGroup::NUMBERING) {
                $this->syncNumberingStarts($values);
            }
        });

        $this->forget();

        return $raised;
    }

    /**
     * Next-code samples and start floors for the Numbering card.
     *
     * Used so SuperAdmin can see "next customer will be CUSTOMER-000005"
     * before saving, and so a start field cannot be typed below the
     * highest number already issued.
     *
     * @return array<string, array{label: string, highest: int, next: int, min: int, sample: string}>
     */
    public function numberingHints(): array
    {
        $pad = max(3, (int) $this->get('numbering_padding'));
        $hints = [];

        foreach (SettingCatalog::numberingStartMap() as $startKey => $sequenceKey) {
            $prefixKey = SettingCatalog::numberingPrefixMap()[$sequenceKey] ?? '';
            $highest = $this->highestIssued($sequenceKey);
            $start = max(1, (int) $this->get($startKey, 1));
            $next = max($highest + 1, $start);
            $prefix = $prefixKey !== '' ? (string) $this->get($prefixKey) : '';
            $label = Str::headline(str_replace(
                ['numbering_', '_start'],
                ['', ''],
                $startKey
            ));

            $row = [
                'label' => $label,
                'highest' => $highest,
                'next' => $next,
                'min' => $highest + 1,
                'sample' => $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT),
            ];

            $hints[$startKey] = $row;

            if ($prefixKey !== '') {
                $hints[$prefixKey] = $row;
            }
        }

        return $hints;
    }

    /**
     * Drop the cached bag so the next read hits the table.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * USB-wedge config consumed by barcode-scanner.js.
     *
     * Values are fixed in config/annigems.php — not SuperAdmin-editable.
     *
     * @return array{inter_keystroke_ms: int, idle_refocus_ms: int, prefix: string, suffix: string, terminators: list<string>, beep_enabled: bool}
     */
    public function scannerConfig(): array
    {
        $terminator = \App\Enums\ScannerTerminator::tryFrom((string) config('annigems.scanner.terminator', 'both'))
            ?? \App\Enums\ScannerTerminator::BOTH;

        return [
            'inter_keystroke_ms' => (int) config('annigems.scanner.inter_keystroke_ms', 80),
            'idle_refocus_ms' => (int) config('annigems.scanner.idle_refocus_ms', 2500),
            'prefix' => (string) config('annigems.scanner.prefix', ''),
            'suffix' => (string) config('annigems.scanner.suffix', ''),
            'terminators' => $terminator->keys(),
            'beep_enabled' => (bool) config('annigems.scanner.beep_enabled', true),
        ];
    }

    /**
     * Highest already-issued number for a code_sequences key.
     */
    public function highestIssued(string $sequenceKey): int
    {
        $last = DB::table('code_sequences')->where('key', $sequenceKey)->value('last_number');

        return (int) ($last ?? 0);
    }

    /**
     * A start at or below the highest issued number cannot rewind the
     * sequence. Clamp it to next-up so a low customer start cannot block
     * saving jewellery prefixes in the same group.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeNumberingStarts(array $values): array
    {
        foreach (SettingCatalog::numberingStartMap() as $settingKey => $sequenceKey) {
            if (! array_key_exists($settingKey, $values)) {
                continue;
            }

            $highest = $this->highestIssued($sequenceKey);
            $values[$settingKey] = max((int) $values[$settingKey], $highest + 1);
        }

        return $values;
    }

    /**
     * Raise code_sequences.last_number so the next issued code equals start.
     *
     * @param  array<string, mixed>  $values
     */
    private function syncNumberingStarts(array $values): void
    {
        foreach (SettingCatalog::numberingStartMap() as $settingKey => $sequenceKey) {
            if (! array_key_exists($settingKey, $values)) {
                continue;
            }

            $start = (int) $values[$settingKey];
            $highest = $this->highestIssued($sequenceKey);

            if ($start <= $highest) {
                continue;
            }

            DB::table('code_sequences')->updateOrInsert(
                ['key' => $sequenceKey],
                ['last_number' => $start - 1, 'updated_at' => now()]
            );
        }
    }

    /**
     * Write the operator-facing history row.
     */
    private function recordHistory(string $key, ?string $old, ?string $new, User $actor): void
    {
        $history = new SettingHistory([
            'scope' => SettingScope::GLOBAL,
            'branch_id' => null,
            'key' => $key,
            'old_value' => $old,
            'new_value' => $new,
        ]);

        $history->save();
    }
}

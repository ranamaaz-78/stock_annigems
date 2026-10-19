<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AttributionFormatter
 *
 * Formats stored attribution snapshots for display. The role string on a
 * record is treated as frozen history — this class never looks up the
 * user's current role, so a promotion cannot rewrite past screens.
 */
class AttributionFormatter
{
    /**
     * Marker written when there is no authenticated user (seeders, jobs, console).
     */
    public const SYSTEM_ROLE = 'system';

    /**
     * Display name for the system marker.
     */
    public const SYSTEM_NAME = 'System';

    /**
     * Neutral name when the user row is missing entirely (should not happen
     * because users are only soft-deleted, but the view must not break).
     */
    public const FALLBACK_NAME = 'Former user';

    /**
     * Human-readable label for a stored role snapshot.
     *
     * Uses the enum label when the snapshot is a known UserRole value.
     * "system" is a marker, not a role. Unknown strings are shown as-is
     * rather than looking anything up live.
     */
    public static function roleLabel(?string $roleSnapshot): string
    {
        if ($roleSnapshot === null || $roleSnapshot === '') {
            return '';
        }

        if ($roleSnapshot === self::SYSTEM_ROLE) {
            return __(self::SYSTEM_NAME);
        }

        return UserRole::tryFrom($roleSnapshot)?->label()
            ?? ucfirst(str_replace('_', ' ', $roleSnapshot));
    }

    /**
     * Format a timestamp in the configured timezone and date_format.
     */
    public static function timestamp(mixed $at): string
    {
        if ($at === null) {
            return '';
        }

        $carbon = $at instanceof CarbonInterface
            ? $at
            : Carbon::parse($at);

        $formatted = setting_datetime($carbon);

        return $formatted === '—' ? '' : $formatted;
    }

    /**
     * Single actor line: "Ali Khan (Cashier) — 12 Mar 2026, 3:42 PM".
     *
     * Deactivated users keep their name plus "(inactive)". Missing users
     * fall back to a neutral name plus the stored role. System writes
     * show "System" with no role parentheses.
     *
     * @param  User|null  $user  Loaded withTrashed when possible
     * @param  string|null  $roleSnapshot  Role stored on the record at write time
     */
    public static function actorLine(?User $user, ?string $roleSnapshot, mixed $at): string
    {
        $when = self::timestamp($at);
        $name = self::actorName($user, $roleSnapshot);
        $inactive = self::inactiveMarker($user);

        if ($roleSnapshot === self::SYSTEM_ROLE || ($user === null && ($roleSnapshot === null || $roleSnapshot === ''))) {
            return $when !== '' ? "{$name} — {$when}" : $name;
        }

        $role = self::roleLabel($roleSnapshot);
        $core = $role !== '' ? "{$name} ({$role})" : $name;
        $core .= $inactive;

        return $when !== '' ? "{$core} — {$when}" : $core;
    }

    /**
     * Display name for an actor: live name, fallback, or "System".
     */
    public static function actorName(?User $user, ?string $roleSnapshot): string
    {
        if ($user !== null) {
            return $user->name !== '' ? $user->name : __(self::FALLBACK_NAME);
        }

        if ($roleSnapshot === self::SYSTEM_ROLE || $roleSnapshot === null || $roleSnapshot === '') {
            return __(self::SYSTEM_NAME);
        }

        return __(self::FALLBACK_NAME);
    }

    /**
     * " (inactive)" suffix when the user is deactivated or soft-deleted.
     * Never returns a blank name — the marker is appended to a real name.
     */
    public static function inactiveMarker(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $inactive = $user->trashed()
            || $user->status === UserStatus::INACTIVE;

        return $inactive ? ' ('.__('inactive').')' : '';
    }

    /**
     * "Created by: …" line. Honours attributionColumnMap() so action records
     * that store performed_by still render through <x-attribution>.
     */
    public static function createdLine(Model $record): string
    {
        $map = self::columnMap($record);
        $roleColumn = $map['created_by_role'] ?? 'created_by_role';

        $user = $record->relationLoaded('creator') ? $record->getRelation('creator') : null;

        return self::actorLine(
            $user,
            $roleColumn !== null ? $record->getAttribute($roleColumn) : null,
            $record->getAttribute('created_at')
        );
    }

    /**
     * "Last updated: …" line, or null when the record has never been edited
     * by a tracked updater (updated_by is empty) or the model is immutable.
     */
    public static function updatedLine(Model $record): ?string
    {
        $map = self::columnMap($record);
        $idColumn = $map['updated_by'] ?? 'updated_by';
        $roleColumn = $map['updated_by_role'] ?? 'updated_by_role';

        if ($idColumn === null && $roleColumn === null) {
            return null;
        }

        $updatedId = $idColumn !== null ? $record->getAttribute($idColumn) : null;
        $updatedRole = $roleColumn !== null ? $record->getAttribute($roleColumn) : null;

        if ($updatedId === null && $updatedRole === null) {
            return null;
        }

        $user = $record->relationLoaded('updater') ? $record->getRelation('updater') : null;

        return self::actorLine(
            $user,
            $updatedRole,
            $record->getAttribute('updated_at')
        );
    }

    /**
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    private static function columnMap(Model $record): array
    {
        if (method_exists($record, 'attributionColumnMap')) {
            return $record->attributionColumnMap();
        }

        return [
            'created_by' => 'created_by',
            'created_by_role' => 'created_by_role',
            'updated_by' => 'updated_by',
            'updated_by_role' => 'updated_by_role',
        ];
    }
}

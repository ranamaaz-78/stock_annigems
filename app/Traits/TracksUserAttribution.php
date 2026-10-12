<?php

namespace App\Traits;

use App\Models\User;
use App\Support\AttributionFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TracksUserAttribution
 *
 * Central writer for who created/changed a record and which role they held
 * at that moment. Apply this trait to every attributable model — that is
 * the only step a developer should need. Do not set created_by / updated_by
 * (or their *_role snapshots) from controllers, requests, or services.
 *
 * Why a snapshot, not a live lookup: if a Cashier creates an invoice in
 * March and is promoted to Manager in June, the March invoice must forever
 * read "Ali Khan (Cashier)". Resolving role from users.role at display
 * time would rewrite history and corrupt the audit trail.
 *
 * created_by is attribution only. It never grants access and never
 * restricts it. All data scoping is by branch_id.
 *
 * Column map: override attributionColumnMap() for action-specific names
 * (e.g. AuditLog uses user_id + user_role; future tables may use
 * performed_by + performed_by_role). Set a side to null to skip it
 * (AuditLog is immutable, so updated_* is null).
 */
trait TracksUserAttribution
{
    /**
     * Strip attribution columns from $fillable and register creating/updating hooks.
     *
     * initialize{Trait} runs from the Eloquent constructor, so even if a
     * model accidentally lists these columns as fillable, they are removed
     * before any mass assignment can occur.
     */
    protected function initializeTracksUserAttribution(): void
    {
        $columns = array_values(array_filter($this->attributionColumnMap()));

        foreach ($columns as $column) {
            $index = array_search($column, $this->fillable, true);
            if ($index !== false) {
                unset($this->fillable[$index]);
            }
        }

        $this->fillable = array_values($this->fillable);
    }

    /**
     * Register automatic snapshot writers.
     */
    public static function bootTracksUserAttribution(): void
    {
        static::creating(function (Model $model): void {
            $model->applyCreatorAttribution();
        });

        static::updating(function (Model $model): void {
            $model->applyUpdaterAttribution();
        });
    }

    /**
     * Default column names. Override on models with action-specific fields.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'created_by',
            'created_by_role' => 'created_by_role',
            'updated_by' => 'updated_by',
            'updated_by_role' => 'updated_by_role',
        ];
    }

    /**
     * User who created the record (includes deactivated / soft-deleted accounts).
     */
    public function creator(): BelongsTo
    {
        $column = $this->attributionColumnMap()['created_by'] ?? 'created_by';

        if ($column === null) {
            return $this->belongsTo(User::class, 'id')->whereRaw('1 = 0');
        }

        return $this->belongsTo(User::class, $column)->withTrashed();
    }

    /**
     * User who last modified the record (includes deactivated accounts).
     *
     * Immutable models (AuditLog) set updated_by to null in the column map;
     * the relation is then always empty so callers do not hit a missing column.
     */
    public function updater(): BelongsTo
    {
        $column = $this->attributionColumnMap()['updated_by'] ?? 'updated_by';

        if ($column === null) {
            return $this->belongsTo(User::class, 'id')->whereRaw('1 = 0');
        }

        return $this->belongsTo(User::class, $column)->withTrashed();
    }

    /**
     * Snapshot the creating actor onto the model.
     *
     * Authenticated request: always overwrite from auth so request input
     * cannot inject a fake creator. Unauthenticated context (seeder, job,
     * console): keep a pre-set actor id if one was assigned in code, and
     * snapshot that user's current role; otherwise write the system marker
     * rather than leaving NULL silently.
     */
    protected function applyCreatorAttribution(): void
    {
        $map = $this->attributionColumnMap();
        $idColumn = $map['created_by'];
        $roleColumn = $map['created_by_role'];

        if ($idColumn === null && $roleColumn === null) {
            return;
        }

        $resolved = $this->resolveAttributionActor($idColumn);

        if ($idColumn !== null) {
            $this->setAttribute($idColumn, $resolved['id']);
        }

        if ($roleColumn !== null) {
            $this->setAttribute($roleColumn, $resolved['role']);
        }
    }

    /**
     * Snapshot the updating actor. Never rewrites created_* columns.
     */
    protected function applyUpdaterAttribution(): void
    {
        $map = $this->attributionColumnMap();
        $idColumn = $map['updated_by'];
        $roleColumn = $map['updated_by_role'];

        if ($idColumn === null && $roleColumn === null) {
            return;
        }

        $resolved = $this->resolveAttributionActor($idColumn);

        if ($idColumn !== null) {
            $this->setAttribute($idColumn, $resolved['id']);
        }

        if ($roleColumn !== null) {
            $this->setAttribute($roleColumn, $resolved['role']);
        }
    }

    /**
     * Resolve the actor id + role snapshot for the current write.
     *
     * @return array{id: int|null, role: string}
     */
    protected function resolveAttributionActor(?string $existingIdColumn): array
    {
        $auth = auth()->user();

        if ($auth instanceof User) {
            return [
                'id' => $auth->id,
                // Snapshot the role held RIGHT NOW, stored as the enum value.
                'role' => $auth->role instanceof \BackedEnum ? $auth->role->value : (string) $auth->role,
            ];
        }

        // No HTTP user: honour an actor id already placed on the model
        // (e.g. AuditLogService passing an explicit actor in a queued job).
        $existingId = $existingIdColumn !== null ? $this->getAttribute($existingIdColumn) : null;

        if ($existingId) {
            $actor = User::query()->withTrashed()->find($existingId);

            return [
                'id' => (int) $existingId,
                'role' => $actor?->role instanceof \BackedEnum
                    ? $actor->role->value
                    : ($actor?->role ? (string) $actor->role : AttributionFormatter::SYSTEM_ROLE),
            ];
        }

        return [
            'id' => null,
            'role' => AttributionFormatter::SYSTEM_ROLE,
        ];
    }
}

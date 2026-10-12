<?php

namespace App\Models;

use App\Enums\ReportExportStatus;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReportExport
 *
 * Record of one export request: what was exported, with which filters, by whom,
 * and where the finished file landed.
 *
 * Deliberately does NOT use the BelongsToBranch trait even though it has a
 * branch_id. A SuperAdmin export legitimately covers all branches and stores
 * null, and the global branch scope would hide those rows from the only actor
 * allowed to see them. Scoping is applied explicitly by the controller instead.
 */
class ReportExport extends Model
{
    use HasUuid;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'report_key',
        'report_title',
        'filters',
        'format',
        'status',
        'file_path',
        'row_count',
        'failure_reason',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'status' => ReportExportStatus::class,
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * An export is requested once and never re-requested, so only the create
     * attribution is stored — matching GoldRate and AuditLog.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'requested_by',
            'created_by_role' => 'requested_by_role',
            'updated_by' => null,
            'updated_by_role' => null,
        ];
    }

    /**
     * Branch the export covered; null means All Branches (SuperAdmin).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Actor who requested it, including deactivated users.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /**
     * Whether the generated file is on disk and ready to download.
     */
    public function isDownloadable(): bool
    {
        return $this->status === ReportExportStatus::COMPLETED
            && $this->file_path !== null;
    }
}

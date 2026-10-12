<?php

namespace App\Enums;

/**
 * ReportExportStatus
 *
 * Lifecycle of a report export.
 *
 * STREAMED is a genuine terminal state, not a variant of COMPLETED: a small
 * export is written straight to the response and never touches disk, so there
 * is nothing to download later. Recording it separately keeps the export list
 * honest — a streamed row shows what was pulled and by whom, without offering
 * a download link that would 404.
 */
enum ReportExportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case STREAMED = 'streamed';
    case FAILED = 'failed';

    /**
     * Human-readable label for the export list.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Queued',
            self::PROCESSING => 'Generating',
            self::COMPLETED => 'Ready',
            self::STREAMED => 'Downloaded',
            self::FAILED => 'Failed',
        };
    }
}

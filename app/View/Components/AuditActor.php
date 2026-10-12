<?php

namespace App\View\Components;

use App\Models\User;
use App\Support\AttributionFormatter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * AuditActor
 *
 * Single muted actor line for an audit-log row, using the snapshotted
 * user_role stored on the log — never the user's current role.
 *
 * Usage: <x-audit-actor :user="$log->user" :role="$log->user_role" :at="$log->created_at" />
 */
class AuditActor extends Component
{
    /**
     * Formatted "Name (Role) — timestamp" line.
     */
    public string $line;

    /**
     * @param  User|null  $user  Actor, withTrashed
     * @param  string|null  $role  Snapshot stored on the audit row
     * @param  mixed  $at  When the action occurred
     */
    public function __construct(?User $user, ?string $role, mixed $at)
    {
        $this->line = AttributionFormatter::actorLine($user, $role, $at);
    }

    /**
     * Render the actor line.
     */
    public function render(): View|Closure|string
    {
        return view('components.audit-actor');
    }
}

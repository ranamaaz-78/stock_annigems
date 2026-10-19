<?php

namespace App\Enums;

/**
 * UserRole
 *
 * Fixed set of system roles. SuperAdmin is system-level (no branch).
 * Admin and below are branch-scoped. Never accept role values from request
 * payloads — always assign server-side via services.
 */
enum UserRole: string
{
    case SUPERADMIN = 'superadmin';
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case CASHIER = 'cashier';
    case INVENTORY_STAFF = 'inventory_staff';

    /**
     * Human-readable label for UI tables and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::SUPERADMIN => 'SuperAdmin',
            self::ADMIN => 'Admin',
            self::MANAGER => 'Manager',
            self::CASHIER => 'Cashier',
            self::INVENTORY_STAFF => 'Inventory Staff',
        });
    }

    /**
     * Whether this role belongs to a branch (everything except SuperAdmin).
     */
    public function requiresBranch(): bool
    {
        return $this !== self::SUPERADMIN;
    }
}

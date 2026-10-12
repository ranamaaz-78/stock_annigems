<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * HasUuid
 *
 * Hybrid public identifier for every URL-addressable model (PATCH 02).
 *
 * Integer `id` remains the primary key and the target of every foreign key —
 * InnoDB clustering, secondary indexes, and joins stay fast on large tables
 * (stock_movements, invoice_items, audit_logs). The `uuid` column is the
 * only identifier that may appear in a URL, form action, link, or JSON
 * payload that reaches the browser.
 *
 * Applying this trait is the only step on a new model. Developers must never
 * generate a UUID by hand, and request input can never set one.
 *
 * UUID choice: Laravel's Str::orderedUuid(). That is UUIDv7 when the
 * Ramsey backend provides it, otherwise Laravel's time-ordered UUID
 * variant. Either way the entropy bits are cryptographically random
 * (unguessable for URL addressing) while the value is roughly
 * time-ordered, so the unique index on `uuid` appends instead of
 * random-inserting. This is NOT the primary key; `id` still owns locality.
 *
 * Not a substitute for authorization. A valid uuid from another branch must
 * still 403 via policies / branch scope. Never accept a uuid from input to
 * *set* identity — only to *look a record up*.
 *
 * Do not use this on tables that are never addressed by URL (invoice_items,
 * stock_movements, audit_logs, pivots, settings, …). Business codes
 * (item_code, invoice_number) and public tokens (qr_token, verification_token)
 * stay separate columns; they are not replaced by uuid.
 */
trait HasUuid
{
    /**
     * Hide the integer PK from serialization and keep uuid off fillable.
     *
     * initialize{Trait} runs from the Eloquent constructor, so even if a
     * model lists `uuid` as fillable it is removed before mass assignment.
     */
    protected function initializeHasUuid(): void
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, ['id'])));

        $index = array_search('uuid', $this->fillable, true);
        if ($index !== false) {
            unset($this->fillable[$index]);
            $this->fillable = array_values($this->fillable);
        }
    }

    /**
     * Generate the public identifier on create; freeze it on update.
     */
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            // Always overwrite — a client-supplied uuid must never stick.
            $model->setAttribute('uuid', (string) Str::orderedUuid());
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('uuid')) {
                $model->setAttribute('uuid', $model->getOriginal('uuid'));
            }
        });
    }

    /**
     * Use the public uuid for route model binding instead of the
     * auto-increment id, so internal ids are never exposed in URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

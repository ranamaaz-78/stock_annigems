<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

/**
 * InvoiceVerificationService
 *
 * Public invoice verification by verification_token only. Never accepts uuid
 * and never exposes internal/sensitive fields.
 */
class InvoiceVerificationService
{
    /**
     * Find invoice by public verification token.
     */
    public function findByToken(string $token): ?Invoice
    {
        return Invoice::query()
            ->withoutGlobalScopes()
            ->with(['branch', 'items.itemStones'])
            ->where('verification_token', $token)
            ->first();
    }

    /**
     * Safe public payload — no staff names, notes, or internal codes beyond print.
     *
     * @return array<string, mixed>|null
     */
    public function publicPayload(Invoice $invoice): ?array
    {
        if ($invoice->status === InvoiceStatus::VOIDED) {
            return [
                'voided' => true,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => setting_date($invoice->invoice_date),
            ];
        }

        return [
            'voided' => false,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => setting_date($invoice->invoice_date),
            'branch_name' => $invoice->branch?->name,
            'customer_name' => $invoice->customer_name_snapshot,
            'grand_total' => (string) $invoice->grand_total,
            'currency' => setting('currency_symbol', config('annigems.currency_symbol')),
            'items' => $invoice->items->map(fn ($item): array => [
                'description' => $item->name_snapshot,
                'weight' => $item->net_weight_snapshot ? $item->net_weight_snapshot.'g' : null,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
        ];
    }
}

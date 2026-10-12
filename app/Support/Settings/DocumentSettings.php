<?php

namespace App\Support\Settings;

use App\Enums\DiscountType;
use App\Enums\TaxType;
use App\Models\User;

/**
 * DocumentSettings
 *
 * SuperAdmin tax and discount rules as they apply to a live quotation
 * (and therefore to the invoice converted from it).
 *
 * Tax type, rate and label are not cashier choices — the catalog is the
 * only source. Discount stays an operator control when enabled, and
 * max_discount_percentage caps every role including Admin.
 */
class DocumentSettings
{
    /**
     * Current tax rule for new and draft quotations.
     *
     * @return array{enabled: bool, type: string, rate: float, label: string}
     */
    public static function tax(): array
    {
        $enabled = (bool) setting('tax_enabled');
        $type = (string) setting('tax_type', TaxType::PERCENTAGE->value);

        if (! in_array($type, [TaxType::PERCENTAGE->value, TaxType::FIXED->value], true)) {
            $type = TaxType::PERCENTAGE->value;
        }

        return [
            'enabled' => $enabled,
            'type' => $enabled ? $type : TaxType::NONE->value,
            'rate' => $enabled ? (float) setting('tax_rate', 0) : 0.0,
            'label' => (string) setting('tax_label', 'GST'),
        ];
    }

    /**
     * Overlay catalog tax (and a disabled discount) onto a document payload.
     *
     * Client-submitted tax_type / tax_rate are discarded so a crafted POST
     * cannot undercut the SuperAdmin rate. POS may send apply_tax=false to
     * skip GST on that ticket; omitting the key keeps the quotation-workspace
     * behaviour (catalog tax always applied when enabled).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToPayload(array $data, ?User $actor = null): array
    {
        $tax = self::tax();
        $applyTax = self::shouldApplyTax($data);

        if ($tax['enabled'] && $applyTax) {
            $data['tax_type'] = $tax['type'];
            $data['tax_rate'] = $tax['rate'];
        } else {
            $data['tax_type'] = TaxType::NONE->value;
            $data['tax_rate'] = 0;
        }

        if (! setting('discount_enabled')) {
            $data['discount_type'] = DiscountType::NONE->value;
            $data['discount_value'] = 0;
        }

        return $data;
    }

    /**
     * POS sends apply_tax explicitly. The quotation workspace omits the key
     * so catalog GST still applies whenever SuperAdmin has tax enabled.
     *
     * @param  array<string, mixed>  $data
     */
    private static function shouldApplyTax(array $data): bool
    {
        if (! array_key_exists('apply_tax', $data)) {
            return true;
        }

        $value = $data['apply_tax'];

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Percentage ceiling for every quotation / invoice discount.
     *
     * Admin is not exempt — the SuperAdmin number is the shop rule.
     */
    public static function maxDiscountPercentage(?User $actor = null): float
    {
        if (! setting('discount_enabled')) {
            return 0.0;
        }

        return (float) setting('max_discount_percentage', 100);
    }
}
